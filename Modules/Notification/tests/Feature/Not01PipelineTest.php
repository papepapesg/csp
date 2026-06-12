<?php

namespace Modules\Notification\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Notification\Database\Seeders\Not01ModelSeeder;
use Modules\Notification\Models\ChannelOperatorConfig;
use Modules\Notification\Models\CustomerNotificationPreference;
use Modules\Notification\Models\NotificationDeliveryAttempt;
use Modules\Notification\Models\NotificationLog;
use Modules\Notification\Models\NotificationRoutingRule;
use Modules\Notification\Models\Template;
use Modules\Notification\Services\BounceService;
use Modules\Notification\Services\NotificationOrchestrator;
use Modules\Notification\Services\RetryScheduler;
use Tests\TestCase;

/**
 * NOT-01 end-to-end pipeline: route -> preference -> regulatory -> render -> dispatch ->
 * audit, plus the failure-handling (retry/fallback/escalate), bounce, and admin paths.
 */
class Not01PipelineTest extends TestCase
{
    use RefreshDatabase;

    private array $invoicePayload = [
        'invoiceNumber' => 'Inv-WIK-2026-000001', 'currency' => 'KES', 'amount' => '2500.00', 'dueDate' => '2026-07-01',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(Not01ModelSeeder::class);
        config()->set('sophix.notification.window.SMS', ['start' => '00:00', 'end' => '23:59']); // don't defer by default
    }

    private function orchestrator(): NotificationOrchestrator
    {
        return app(NotificationOrchestrator::class);
    }

    public function test_invoice_issued_renders_pdf_and_dispatches_both_channels(): void
    {
        $log = $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_A', 'sourceEntityId' => 'inv_1', 'sourceEventId' => 'evt_1',
            'contacts' => ['EMAIL' => 'a@example.com', 'SMS' => '+254712345678'],
        ]);

        $this->assertSame(NotificationLog::DISPATCHED, $log->final_status);
        $this->assertEqualsCanonicalizing(['EMAIL', 'SMS'], $log->channels_attempted);
        $this->assertSame(2, NotificationDeliveryAttempt::where('notification_id', $log->id)->where('status', 'SENT')->count());
        // PDF stored as a FileObject + PdfReady emitted (R-NOT-01-D-5/D-6).
        $this->assertDatabaseHas('file_object', ['owner_id' => 'inv_1', 'mime_type' => 'application/pdf']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PdfReady']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'NotificationDispatched']);
    }

    public function test_idempotency_skips_duplicate_source_event(): void
    {
        $opts = ['customerId' => 'cust_A', 'sourceEntityId' => 'inv_2', 'sourceEventId' => 'evt_dup', 'contacts' => ['SMS' => '+254712345678']];
        $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '6402.50', 'currency' => 'KES', 'payByDate' => '2026-05-25'], $opts);
        $again = $this->orchestrator()->ingest('PtpRegistered', 'WIK', [], $opts);

        $this->assertNull($again);
        $this->assertSame(1, NotificationLog::where('source_event_id', 'evt_dup')->count());
    }

    public function test_marketing_opt_out_suppresses(): void
    {
        NotificationRoutingRule::query()->create([
            'operator_code' => 'WIK', 'event_type' => 'PromoBlast', 'channel' => 'SMS',
            'template_purpose_code' => 'PTP_CONFIRMATION', 'priority' => 1, 'urgency' => 'NORMAL',
            'category' => NotificationRoutingRule::CATEGORY_MARKETING, 'enabled' => true,
        ]);
        CustomerNotificationPreference::query()->create([
            'customer_id' => 'cust_M', 'operator_code' => 'WIK', 'sms_opt_in' => false, 'email_opt_in' => true, 'locale' => 'en',
        ]);

        $log = $this->orchestrator()->ingest('PromoBlast', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_M', 'contacts' => ['SMS' => '+254712345678'],
        ]);

        $this->assertSame(NotificationLog::SUPPRESSED, $log->final_status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'NotificationSuppressed']);
    }

    public function test_transactional_sms_ignores_marketing_opt_out(): void
    {
        CustomerNotificationPreference::query()->create([
            'customer_id' => 'cust_T', 'operator_code' => 'WIK', 'sms_opt_in' => false, 'email_opt_in' => false, 'locale' => 'en',
        ]);

        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '100', 'currency' => 'KES', 'payByDate' => '2026-07-01'], [
            'customerId' => 'cust_T', 'contacts' => ['SMS' => '+254712345678', 'EMAIL' => 't@example.com'],
        ]);

        // PTP is TRANSACTIONAL -> opt-out is ignored -> both channels sent.
        $this->assertSame(NotificationLog::DISPATCHED, $log->final_status);
    }

    public function test_permanent_recipient_falls_back_to_next_channel(): void
    {
        $log = $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_F', 'sourceEntityId' => 'inv_f', 'contacts' => ['EMAIL' => 'not-an-email', 'SMS' => '+254712345678'],
        ]);

        $this->assertSame(NotificationLog::PARTIALLY_DISPATCHED, $log->final_status);
        $this->assertSame(1, NotificationDeliveryAttempt::where('notification_id', $log->id)->where('channel', 'EMAIL')->where('failure_category', 'PERMANENT_RECIPIENT')->count());
        $this->assertSame(1, NotificationDeliveryAttempt::where('notification_id', $log->id)->where('channel', 'SMS')->where('status', 'SENT')->count());
    }

    public function test_all_permanent_recipient_is_undeliverable(): void
    {
        $log = $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_U', 'sourceEntityId' => 'inv_u', 'contacts' => ['EMAIL' => 'bad', 'SMS' => 'bad'],
        ]);

        $this->assertSame(NotificationLog::UNDELIVERABLE, $log->final_status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'NotificationUndeliverable']);
    }

    public function test_transient_failure_schedules_retry_then_escalates(): void
    {
        config()->set('sophix.notification.retry_backoff_seconds', [60]); // 1 retry then escalate

        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_R', 'sourceEntityId' => 'ptp_r', 'contacts' => ['SMS' => '+00000123'],
        ]);

        $attempt = NotificationDeliveryAttempt::where('notification_id', $log->id)->where('channel', 'SMS')->first();
        $this->assertSame('PENDING_RETRY', $attempt->status);
        $this->assertNotNull($attempt->next_attempt_at);

        // Force it due and run the scanner -> retry fails again -> ESCALATED.
        $attempt->update(['next_attempt_at' => now()->subMinute()]);
        app(RetryScheduler::class)->run('WIK');

        $this->assertSame(1, NotificationDeliveryAttempt::where('notification_id', $log->id)->where('status', 'ESCALATED')->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'NotificationEscalated']);
    }

    public function test_missing_template_queues_render_failure(): void
    {
        Template::query()->where('template_purpose_code', 'PTP_CONFIRMATION')->delete();

        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_X', 'sourceEntityId' => 'ptp_x', 'contacts' => ['SMS' => '+254712345678'],
        ]);

        $this->assertDatabaseHas('render_failure_queue', ['source_entity_id' => 'ptp_x', 'failure_reason' => 'TEMPLATE_NOT_FOUND']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'RenderFailed']);
        // No channel could render -> suppressed.
        $this->assertSame(NotificationLog::SUPPRESSED, $log->final_status);
    }

    public function test_hard_bounce_marks_email_invalid_and_skips_email(): void
    {
        CustomerNotificationPreference::query()->create(['customer_id' => 'cust_B', 'operator_code' => 'WIK', 'locale' => 'en']);
        app(BounceService::class)->processBounce('cust_B', 'HARD');

        $this->assertSame('INVALID', CustomerNotificationPreference::forCustomer('cust_B')->email_status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'BounceProcessed']);

        $log = $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_B', 'sourceEntityId' => 'inv_b', 'contacts' => ['EMAIL' => 'b@example.com', 'SMS' => '+254712345678'],
        ]);

        $this->assertEquals(['SMS'], $log->channels_attempted); // email skipped (INVALID)
    }

    public function test_time_window_defers_non_urgent_sms(): void
    {
        // A window that excludes "now" forces deferral.
        $hour = (int) now(config('sophix.notification.timezone'))->format('H');
        $start = str_pad((string) (($hour + 2) % 24), 2, '0', STR_PAD_LEFT).':00';
        $end = str_pad((string) (($hour + 3) % 24), 2, '0', STR_PAD_LEFT).':00';
        config()->set('sophix.notification.window.SMS', ['start' => $start, 'end' => $end]);

        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_W', 'sourceEntityId' => 'ptp_w', 'contacts' => ['SMS' => '+254712345678'],
        ]);

        $attempt = NotificationDeliveryAttempt::where('notification_id', $log->id)->where('channel', 'SMS')->first();
        $this->assertSame('PENDING_RETRY', $attempt->status);
        $this->assertStringContainsString('deferred', (string) $attempt->failure_detail);
    }

    public function test_locale_fallback_to_english(): void
    {
        CustomerNotificationPreference::query()->create(['customer_id' => 'cust_FR', 'operator_code' => 'WIK', 'locale' => 'fr']);

        // No fr templates seeded -> falls back to en -> still dispatches.
        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_FR', 'sourceEntityId' => 'ptp_fr', 'contacts' => ['SMS' => '+254712345678'],
        ]);

        $this->assertSame(NotificationLog::DISPATCHED, $log->final_status);
    }

    // ---- Admin + customer REST ----

    public function test_admin_template_upload_activate_then_resolves(): void
    {
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('TEMPLATE_MANAGER');
        Sanctum::actingAs($admin);

        $created = $this->postJson('/api/admin/templates', [
            'template_format' => 'SMS_TEXT', 'template_purpose_code' => 'WELCOME', 'locale' => 'en',
            'template_payload' => 'Welcome {{name}}!',
        ])->assertCreated()->assertJsonPath('status', 'DRAFT')->json();

        $this->assertNull(Template::resolve('WIK', 'SMS_TEXT', 'WELCOME', 'en'));

        $this->postJson("/api/admin/templates/{$created['template_id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertNotNull(Template::resolve('WIK', 'SMS_TEXT', 'WELCOME', 'en'));
    }

    public function test_admin_template_preview_renders_text_and_pdf(): void
    {
        $mgr = User::factory()->create(['operator_code' => 'WIK']);
        $mgr->assignRole('TEMPLATE_MANAGER');
        Sanctum::actingAs($mgr);

        // The studio's "Render on server" path — text format returns rendered output.
        $sms = Template::where('template_format', 'SMS_TEXT')->where('template_purpose_code', 'INVOICE_CYCLE_POSTPAID')->first();
        $this->postJson("/api/admin/templates/{$sms->id}/preview", ['sample_data' => $this->invoicePayload])
            ->assertOk()->assertJsonPath('format', 'SMS_TEXT')
            ->assertJsonFragment(['rendered' => 'Invoice Inv-WIK-2026-000001: KES 2500.00 due 2026-07-01.']);

        // PDF format returns a byte count (the studio shows "PDF rendered — N bytes").
        $pdf = Template::where('template_format', 'PDF')->first();
        $this->postJson("/api/admin/templates/{$pdf->id}/preview", ['sample_data' => $this->invoicePayload])
            ->assertOk()->assertJsonPath('format', 'PDF');
    }

    public function test_customer_can_read_and_update_preferences(): void
    {
        $cust = User::factory()->create(['operator_code' => 'WIK']);
        $cust->assignRole('CUSTOMER');
        $cust->forceFill(['customer_id' => 'cust_SELF'])->save();
        Sanctum::actingAs($cust);

        $this->getJson('/api/notifications/preferences')->assertOk()->assertJsonPath('email_opt_in', true);

        $this->putJson('/api/notifications/preferences', ['sms_opt_in' => false, 'locale' => 'fr'])
            ->assertOk()->assertJsonPath('preference.sms_opt_in', false);

        $this->assertSame('fr', CustomerNotificationPreference::forCustomer('cust_SELF')->locale);
    }

    public function test_admin_pause_routing_disables_event(): void
    {
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('NOTIFICATION_SENDER');
        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/notifications/routing/pause', ['event_type' => 'InvoiceIssued', 'enabled' => false])
            ->assertOk()->assertJsonPath('updated', 2);

        // With routing paused, the event no longer notifies.
        $log = $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_P', 'sourceEntityId' => 'inv_p', 'contacts' => ['SMS' => '+254712345678'],
        ]);
        $this->assertNull($log);
    }

    public function test_admin_dashboard_reports_counts(): void
    {
        $this->orchestrator()->ingest('InvoiceIssued', 'WIK', $this->invoicePayload, [
            'customerId' => 'cust_D', 'sourceEntityId' => 'inv_d', 'contacts' => ['EMAIL' => 'd@example.com', 'SMS' => '+254712345678'],
        ]);

        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('NOTIFICATION_SENDER');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/notifications/dashboard')->assertOk()
            ->assertJsonPath('notifications_by_final_status.DISPATCHED', 1);
    }

    public function test_unconfigured_channel_is_permanent_failure(): void
    {
        ChannelOperatorConfig::query()->where('channel', 'SMS')->update(['enabled' => false]);

        $log = $this->orchestrator()->ingest('PtpRegistered', 'WIK', ['amount' => '1', 'currency' => 'KES', 'payByDate' => 'x'], [
            'customerId' => 'cust_C', 'sourceEntityId' => 'ptp_c', 'contacts' => ['SMS' => '+254712345678'],
        ]);

        $attempt = NotificationDeliveryAttempt::where('notification_id', $log->id)->first();
        $this->assertSame('PERMANENT_TEMPLATE', $attempt->failure_category);
    }
}
