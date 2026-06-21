<?php

namespace Modules\Notification\Tests\Feature;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Modules\Notification\Database\Seeders\Icn01Seeder;
use Modules\Notification\Icn\ChannelDispatchResult;
use Modules\Notification\Icn\RecipientInfo;
use Modules\Notification\Icn\RenderedMessage;
use Modules\Notification\Icn\Services\AckService;
use Modules\Notification\Icn\Services\StaffNotificationService;
use Modules\Notification\Icn\Services\StaffNotificationSweeper;
use Modules\Notification\Icn\StaffChannelAdapter;
use Modules\Notification\Models\Icn\StaffGroupMembership;
use Modules\Notification\Models\Icn\StaffNotification;
use Modules\Notification\Models\Icn\StaffNotificationAdapterBinding;
use Modules\Notification\Models\Icn\StaffNotificationChannelConfig;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;
use Modules\Notification\Models\Icn\StaffNotificationTemplate;
use Modules\Notification\Models\Icn\StaffNotificationUserChannelIdentity;
use Modules\Notification\Models\Icn\StaffNotificationUserPref;
use Tests\TestCase;

/**
 * ICN-01 staff internal communications: candidate-group fan-out, multi-channel dispatch via
 * the binding/adapter layer, ACK suppression, fallback modes, retry/expiry, and the staff-only
 * scope. The four supervisors of Jane's KYC L1 are the running example.
 */
class Icn01Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(Icn01Seeder::class);
        $this->seedGroup('kenya-l1-kyc', ['sup_01', 'sup_02', 'sup_03', 'sup_04']);
    }

    private function seedGroup(string $group, array $users): void
    {
        foreach ($users as $u) {
            StaffGroupMembership::query()->create(['operator_code' => 'WIK', 'group_code' => $group, 'user_id' => $u]);
            // a directory profile so email/slack fallback resolution works
            User::factory()->create(['uid' => $u, 'operator_code' => 'WIK', 'email' => "{$u}@wik.wananchi.com"]);
            StaffNotificationUserChannelIdentity::query()->create(['user_id' => $u, 'channel' => 'EMAIL', 'operator_code' => 'WIK', 'identity_jsonb' => ['address' => "{$u}@wik.wananchi.com"], 'source' => 'directory-sync', 'verified' => true]);
        }
    }

    private function svc(): StaffNotificationService
    {
        return app(StaffNotificationService::class);
    }

    private function kycPayload(array $over = []): array
    {
        return array_merge([
            'operatorCode' => 'WIK', 'sourceModule' => 'FUL-02', 'sourceTaskId' => 'cam_task_jane',
            'sourceBusinessKey' => 'ord_jane', 'candidateGroup' => 'kenya-l1-kyc', 'templateCode' => 'kyc-l1-approval-needed',
            'templateVariables' => ['customerName' => 'Jane Mwangi', 'orderId' => 'ord_jane', 'franchiseCode' => 'KE-NRB-04', 'deeplinkUrl' => 'https://bo/t/1'],
            'urgency' => 'medium',
        ], $over);
    }

    public function test_fan_out_creates_parent_and_delivery_rows_per_recipient_channel(): void
    {
        // Make all supervisors logged in so IN_APP_PUSH dispatches.
        foreach (['sup_01', 'sup_02', 'sup_03', 'sup_04'] as $u) {
            Cache::put('icn:session:'.$u, true, 3600);
        }

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $this->assertSame(4, $n->expected_recipients);
        $this->assertSame(StaffNotification::DISPATCHED, $n->status);
        // 4 supervisors x 3 channels (EMAIL, SLACK, IN_APP_PUSH).
        $this->assertSame(12, StaffNotificationDelivery::where('notification_id', $n->notification_id)->count());
        $this->assertSame(12, StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('status', 'DISPATCHED')->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationCreated']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationDelivered']);
    }

    public function test_idempotency_key_replays_without_redispatch(): void
    {
        $first = $this->svc()->dispatch($this->kycPayload(), 'idem-jane');
        $second = $this->svc()->dispatch($this->kycPayload(), 'idem-jane');

        $this->assertFalse($first['replay']);
        $this->assertTrue($second['replay']);
        $this->assertSame($first['notification']->notification_id, $second['notification']->notification_id);
        $this->assertSame(1, StaffNotification::count());
    }

    public function test_pending_approval_notifies_the_approver_group(): void
    {
        // EM-CFG-04 -> ICN-01: a pending approval whose policy names an approver group
        // (here the seeded kyc supervisors) raises a staff notification to that group.
        Context::setOperatorCode('WIK');
        ApprovalDefinition::defineChain('WIK', 'KYC_DECISION', null, [
            ['approver_kind' => 'ROLE', 'approver_roles' => ['kenya-l1-kyc']],
        ]);

        app(ApprovalService::class)->request([
            'operator_code' => 'WIK', 'entity_type' => 'KYC_DECISION', 'entity_ref' => 'kyc-1',
        ]);
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();

        $notif = StaffNotification::query()->where('template_code', 'approval-needed')->first();
        $this->assertNotNull($notif);
        $this->assertSame('kenya-l1-kyc', $notif->candidate_group);
        $this->assertSame(4, $notif->expected_recipients); // the four KYC supervisors
    }

    public function test_empty_candidate_group_creates_expired_notification(): void
    {
        $n = $this->svc()->dispatch($this->kycPayload(['candidateGroup' => 'ghost-group']))['notification'];

        $this->assertSame(StaffNotification::EXPIRED, $n->status);
        $this->assertSame('NO_RECIPIENTS', $n->expiry_reason);
        $this->assertSame(0, StaffNotificationDelivery::where('notification_id', $n->notification_id)->count());
    }

    public function test_first_ack_suppresses_other_pending_deliveries(): void
    {
        // No sessions -> IN_APP_PUSH stays PENDING; EMAIL/SLACK dispatch.
        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $result = app(AckService::class)->acknowledge($n, 'sup_01', 'EMAIL');
        $n = $result['notification'];

        $this->assertSame(StaffNotification::ACKNOWLEDGED, $n->status);
        $this->assertSame('sup_01', $n->acknowledged_by);
        // sup_01's EMAIL is ACKNOWLEDGED; all remaining PENDING (the IN_APP_PUSH rows) are SUPPRESSED.
        $this->assertSame(0, StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('status', 'PENDING')->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationAcknowledged']);
    }

    public function test_second_ack_is_idempotent(): void
    {
        $n = $this->svc()->dispatch($this->kycPayload())['notification'];
        app(AckService::class)->acknowledge($n, 'sup_01', 'EMAIL');
        $again = app(AckService::class)->acknowledge($n->refresh(), 'sup_02', 'EMAIL');

        $this->assertTrue($again['idempotent']);
        $this->assertSame('sup_01', $n->refresh()->acknowledged_by); // unchanged
    }

    public function test_user_suppress_channel_yields_suppressed_row(): void
    {
        StaffNotificationUserPref::query()->create(['user_id' => 'sup_01', 'operator_code' => 'WIK', 'suppress_channels' => ['EMAIL']]);

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $email = StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('recipient_user_id', 'sup_01')->where('channel', 'EMAIL')->first();
        $this->assertSame('SUPPRESSED', $email->status);
        $this->assertSame('USER_SUPPRESSED', $email->failure_reason);
    }

    public function test_quiet_hours_suppress_non_high_but_high_overrides(): void
    {
        // 24h quiet window guarantees "now" is inside it.
        StaffNotificationUserPref::query()->create(['user_id' => 'sup_01', 'operator_code' => 'WIK', 'quiet_hours_start' => '00:00', 'quiet_hours_end' => '23:59', 'quiet_hours_timezone' => 'UTC']);

        $medium = $this->svc()->dispatch($this->kycPayload())['notification'];
        $this->assertSame(3, StaffNotificationDelivery::where('notification_id', $medium->notification_id)->where('recipient_user_id', 'sup_01')->where('status', 'SUPPRESSED')->count());

        // refund-approval-needed is high urgency -> bypasses quiet hours.
        $high = $this->svc()->dispatch([
            'operatorCode' => 'WIK', 'sourceModule' => 'BIL-05', 'candidateGroup' => 'kenya-l1-kyc',
            'templateCode' => 'refund-approval-needed', 'urgency' => 'high',
            'templateVariables' => ['refundRequestId' => 'rr1', 'customerName' => 'Jane', 'accountNumber' => 'acct1', 'amount' => '100', 'currency' => 'KES', 'reasonCode' => 'GOODWILL', 'deeplinkUrl' => 'https://bo/r/1'],
        ])['notification'];
        $this->assertSame(0, StaffNotificationDelivery::where('notification_id', $high->notification_id)->where('recipient_user_id', 'sup_01')->where('status', 'SUPPRESSED')->count());
    }

    public function test_missing_required_variable_is_rejected(): void
    {
        $this->expectExceptionMessage('Missing template variables');
        $this->svc()->dispatch($this->kycPayload(['templateVariables' => ['customerName' => 'Jane']]));
    }

    public function test_render_warning_emitted_for_missing_optional_at_render_time(): void
    {
        // franchiseCode is required so caught at ingress; drop it from a template's required set
        // to exercise the soft «MISSING» render path instead.
        StaffNotificationTemplate::query()
            ->where('template_code', 'kyc-l1-approval-needed')->update(['required_variables' => ['customerName', 'deeplinkUrl']]);

        $n = $this->svc()->dispatch($this->kycPayload(['templateVariables' => ['customerName' => 'Jane', 'deeplinkUrl' => 'https://bo/t/1']]))['notification'];

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationTemplateRenderWarning']);
        $this->assertNotNull($n->notification_id);
    }

    public function test_direct_send_reaches_an_explicit_address(): void
    {
        // No group, no staff user — just an explicit email address the caller supplies.
        $res = $this->svc()->dispatchDirect([
            'operatorCode' => 'WIK', 'templateCode' => 'approval-needed',
            'templateVariables' => ['entityType' => 'ADJUSTMENT', 'requestId' => 'appr_x', 'deeplinkUrl' => 'https://bo/a/1'],
            'recipients' => [['channel' => 'EMAIL', 'address' => 'external.director@partner.example']],
            'sourceModule' => 'APPROVALS', 'sourceBusinessKey' => 'appr_x',
        ]);
        $n = $res['notification'];

        $this->assertSame('DIRECT', $n->candidate_group);
        $d = StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('channel', 'EMAIL')->first();
        $this->assertSame('DISPATCHED', $d->status);
        $this->assertSame('external.director@partner.example', $d->recipient_identity['address']);
    }

    public function test_user_stage_approval_emails_the_named_director_directly(): void
    {
        // A policy whose stage is a NAMED USER (a director with an invited login, no platform role).
        $director = User::factory()->create(['operator_code' => 'WIK', 'email' => 'cvm.director@wik.sn', 'status' => 'INVITED']);
        ApprovalDefinition::defineChain('WIK', 'DIRECTOR_SIGNOFF', null, [
            ['approver_kind' => 'USER', 'approver_user_ref' => $director->uid, 'approver_email' => $director->email],
        ]);

        $req = app(ApprovalService::class)->request(['entity_type' => 'DIRECTOR_SIGNOFF', 'entity_ref' => 'x1', 'requested_by' => 'u_maker']);
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful(); // fires the EM-CFG-04 -> ICN bridge

        $n = StaffNotification::query()->where('source_business_key', $req->request_id)->where('candidate_group', 'DIRECT')->first();
        $this->assertNotNull($n, 'the named director should be notified directly');
        $d = StaffNotificationDelivery::where('notification_id', $n->notification_id)->first();
        $this->assertSame('cvm.director@wik.sn', $d->recipient_identity['address']);
        $this->assertSame('DISPATCHED', $d->status);
    }

    public function test_disabled_binding_suppresses_that_channel(): void
    {
        StaffNotificationAdapterBinding::query()->where('channel', 'SLACK')->update(['enabled' => false]);

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $this->assertSame(4, StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('channel', 'SLACK')->where('status', 'SUPPRESSED')->where('failure_reason', 'ADAPTER_BINDING_DISABLED')->count());
    }

    public function test_unknown_adapter_terminally_fails(): void
    {
        StaffNotificationAdapterBinding::query()->where('channel', 'EMAIL')->update(['adapter_impl' => 'does-not-exist']);

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $this->assertSame(4, StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('channel', 'EMAIL')->where('status', 'TERMINALLY_FAILED')->where('failure_reason', 'ADAPTER_NOT_REGISTERED')->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationDeliveryFailed']);
    }

    public function test_sequential_until_dispatch_stops_at_first_success(): void
    {
        StaffNotificationChannelConfig::query()->where('operator_code', 'WIK')->update(['fallback_mode' => 'SEQUENTIAL_UNTIL_DISPATCH', 'default_priority' => ['IN_APP_PUSH', 'SLACK', 'EMAIL']]);
        // No session -> IN_APP_PUSH (idx0) returns NO_ACTIVE_SESSION (PENDING), so SLACK (idx1) is tried and dispatches.
        $n = $this->svc()->dispatch($this->kycPayload(['candidateGroup' => 'kenya-l1-kyc']))['notification'];

        $sup1 = StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('recipient_user_id', 'sup_01')->orderBy('channel_priority_idx')->get();
        $this->assertSame('SLACK', $sup1->firstWhere('status', 'DISPATCHED')?->channel);
        // EMAIL (idx2) is superseded once SLACK dispatched.
        $this->assertSame('SUPPRESSED', $sup1->firstWhere('channel', 'EMAIL')?->status);
    }

    public function test_transient_failure_retries_then_terminally_fails_and_reaches_noone(): void
    {
        StaffNotificationChannelConfig::query()->where('operator_code', 'WIK')->update(['retry_max_attempts' => 2, 'enabled_channels' => ['EMAIL'], 'default_priority' => ['EMAIL']]);
        // Point everyone's email at a transient-failing address.
        StaffNotificationUserChannelIdentity::query()->update(['identity_jsonb' => ['address' => 'x@transient.test']]);

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];
        $d = StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('recipient_user_id', 'sup_01')->first();
        $this->assertSame('FAILED', $d->status); // first attempt failed, retry scheduled

        // Force due + sweep until terminal.
        StaffNotificationDelivery::where('notification_id', $n->notification_id)->update(['next_retry_at' => now()->subMinute()]);
        app(StaffNotificationSweeper::class)->retryDue('WIK');

        $this->assertSame('TERMINALLY_FAILED', $d->refresh()->status);
        $this->assertSame(StaffNotification::EXPIRED, $n->refresh()->status);
        $this->assertSame('ALL_CHANNELS_EXHAUSTED', $n->expiry_reason);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationFailedToReachAnyone']);
    }

    public function test_expiry_sweep_expires_unacked_notification(): void
    {
        $n = $this->svc()->dispatch($this->kycPayload())['notification'];
        $n->update(['expires_at' => now()->subHour()]);

        app(StaffNotificationSweeper::class)->expireWindow('WIK');

        $this->assertSame(StaffNotification::EXPIRED, $n->refresh()->status);
        $this->assertSame('ACK_WINDOW', $n->expiry_reason);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'StaffNotificationExpired']);
    }

    public function test_new_channel_provider_plugs_in_via_config_and_binding_only(): void
    {
        config()->set('sophix.icn.adapter_implementations',
            config('sophix.icn.adapter_implementations') + ['whatsapp-business' => FakeWhatsAppStaffAdapter::class]);
        StaffNotificationTemplate::query()->create([
            'operator_code' => 'WIK', 'template_code' => 'kyc-l1-approval-needed', 'channel' => 'WHATSAPP',
            'body_template' => 'KYC {{customerName}}', 'required_variables' => ['customerName'], 'display_name' => 'wa', 'enabled' => true,
        ]);
        StaffNotificationChannelConfig::query()->where('operator_code', 'WIK')->update(['enabled_channels' => ['WHATSAPP'], 'default_priority' => ['WHATSAPP']]);
        StaffNotificationAdapterBinding::query()->create(['operator_code' => 'WIK', 'channel' => 'WHATSAPP', 'adapter_impl' => 'whatsapp-business', 'config_jsonb' => [], 'enabled' => true, 'updated_by' => 'seed']);

        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $this->assertSame(4, StaffNotificationDelivery::where('notification_id', $n->notification_id)->where('channel', 'WHATSAPP')->where('status', 'DISPATCHED')->count());
    }

    public function test_dispatch_endpoint_requires_permission_and_returns_202(): void
    {
        foreach (['sup_01', 'sup_02', 'sup_03', 'sup_04'] as $u) {
            Cache::put('icn:session:'.$u, true, 3600);
        }
        $svc = User::factory()->create(['operator_code' => 'WIK']);
        $svc->assignRole('ICN_SERVICE');
        Sanctum::actingAs($svc);

        $this->postJson('/api/staff-notifications', $this->kycPayload(), ['Idempotency-Key' => 'http-jane'])
            ->assertStatus(202)->assertJsonPath('expectedRecipients', 4);

        // A role without dispatch permission is forbidden.
        $other = User::factory()->create(['operator_code' => 'WIK']);
        $other->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($other);
        $this->postJson('/api/staff-notifications', $this->kycPayload())->assertForbidden();
    }

    public function test_inbox_and_ack_over_http(): void
    {
        $n = $this->svc()->dispatch($this->kycPayload())['notification'];

        $sup = User::query()->where('uid', 'sup_01')->firstOrFail();
        Sanctum::actingAs($sup);

        $this->getJson('/api/staff-notifications/inbox')->assertOk()
            ->assertJsonPath('notifications.0.sourceModule', 'FUL-02');

        $this->postJson("/api/staff-notifications/{$n->notification_id}/ack", ['recipientUserId' => 'sup_01', 'ackChannel' => 'EMAIL'])
            ->assertOk()->assertJsonPath('status', 'ACKNOWLEDGED');

        // Cannot ack on someone else's behalf.
        $this->postJson("/api/staff-notifications/{$n->notification_id}/ack", ['recipientUserId' => 'sup_02'])
            ->assertStatus(422);
    }
}

/** A staff WhatsApp provider, shipped exactly as a deployment would add one. */
class FakeWhatsAppStaffAdapter implements StaffChannelAdapter
{
    public function adapterImplCode(): string
    {
        return 'whatsapp-business';
    }

    public function init(array $config): void {}

    public function dispatch(StaffNotificationDelivery $delivery, RenderedMessage $message, RecipientInfo $recipient): ChannelDispatchResult
    {
        return ChannelDispatchResult::ok('wa_'.bin2hex(random_bytes(4)));
    }

    public function resolveIdentity(array $userProfile): ?array
    {
        return null;
    }
}
