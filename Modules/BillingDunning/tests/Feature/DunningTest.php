<?php

namespace Modules\Billing\Dunning\Tests\Feature;

use App\Foundation\Events\Outbox\OutboxEvent;
use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Dunning\Database\Seeders\DunningPolicySeeder;
use Modules\Billing\Dunning\Listeners\DunningEventBridge;
use Modules\Billing\Dunning\Models\DunningProgram;
use Modules\Billing\Dunning\Models\DunningState;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Dunning\Services\DunningService;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\RestrictionCatalogSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * BIL-04 Dunning Engine — the per-subscription escalation state machine driven by
 * the versioned dunning_program catalog. The scanner advances one level per pass
 * at each level's grace period (WARNING → RESTRICTED → SUSPENDED → review →
 * TERMINATED), firing that level's workflow action; payment/top-up retreats or
 * clears it. The program version is pinned at entry, so policy edits never disturb
 * an in-flight episode. BIL-04 orchestrates; the restrict/suspend/terminate money
 * and lifecycle mutations live in their owning modules.
 */
class DunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(RestrictionCatalogSeeder::class);
        $this->seed(DunningPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function overdueInvoice(string $account, float $amount = 1000, int $daysAgo = 40): void
    {
        Invoice::query()->create([
            'operator_code' => 'WIK', 'account_id' => $account, 'currency' => 'KES',
            'status' => Invoice::OPEN, 'issue_date' => now()->subDays($daysAgo + 7), 'due_date' => now()->subDays($daysAgo),
            'subtotal_amount' => $amount, 'total_amount' => $amount, 'amount_due' => $amount,
        ]);
    }

    /**
     * EXPECTATION — overdue debt enters dunning at level 1.
     * An account with a past-due invoice, scanned, advances 0 → 1 (WARNING) and
     * emits DunningStageAdvanced — the event NOT-01 turns into the customer notice.
     */
    public function test_scan_advances_level_zero_to_warning(): void
    {
        $this->overdueInvoice('acct_w');

        $this->postJson('/api/dunning/run')->assertOk()->assertJsonPath('advanced', 1);

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_w', 'current_level' => 1]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DunningStageAdvanced']);
    }

    /**
     * EXPECTATION — reaching the SUSPEND level suspends the service.
     * An episode sitting at RESTRICTED past its grace advances to SUSPENDED, whose
     * action fires the SUB-WF suspend-for-non-payment flow: the subscription goes
     * SUSPENDED and SubscriptionSuspendedForNonPayment is emitted. BIL-04 orchestrates;
     * the lifecycle module does the actual suspend.
     */
    public function test_escalation_to_suspend_triggers_subscription_suspension(): void
    {
        $subId = $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'acct_s', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->json('subscription_id');
        Subscription::find($subId)->update(['status_code' => 'ACTIVE']);

        $this->overdueInvoice('acct_s');
        // Already at level 2 (restricted) for 30 days -> next scan suspends.
        DunningState::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acct_s', 'subscription_id' => $subId,
            'current_level' => 2, 'entered_level_at' => now()->subDays(30), 'status' => 'ACTIVE',
        ]);

        $this->postJson('/api/dunning/run')->assertOk();
        Artisan::call('sophix:workflow:work', ['--once' => true]); // run the suspend flow

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_s', 'current_level' => 3]);
        $this->assertSame('SUSPENDED', Subscription::find($subId)->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionSuspendedForNonPayment']);
    }

    /**
     * EXPECTATION — an account risk flag escalates faster (R-ILM-F-3).
     * With an affects_dunning flag (NPD) set, the level grace window is waived: the
     * scan advances even though no grace time has elapsed.
     */
    public function test_dunning_accelerant_flag_waives_grace(): void
    {
        // R-ILM-F-3: an NPD (affects_dunning) flag escalates past the level-1 grace window.
        $this->seed(AccountFlagCatalogSeeder::class);
        $this->overdueInvoice('acct_npd');
        // At level 1, entered just now — grace (7d) has NOT elapsed, so normally no advance.
        DunningState::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acct_npd', 'current_level' => 1,
            'entered_level_at' => now(), 'status' => 'ACTIVE',
        ]);
        \Modules\Ilm\Models\Customer::query()->create([
            'customer_id' => 'c_npd', 'operator_code' => 'WIK', 'type' => 'RES', 'name' => 'NPD', 'primary_msisdn' => '+254700999888',
        ]);
        $account = CustomerAccount::query()->create([
            'account_id' => 'acct_npd', 'operator_code' => 'WIK', 'account_number' => '009-3', 'customer_id' => 'c_npd',
            'service_address' => 'Nairobi', 'status' => 'ACTIVE', 'sub_status' => 'active',
        ]);
        app(AccountService::class)->setFlag($account, 'NPD');

        // The flag waives grace -> the scan advances to level 2 despite no time elapsed.
        app(DunningService::class)->scan();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_npd', 'current_level' => 2]);
    }

    /**
     * EXPECTATION — escalation is monotonic, one level per pass (D-2).
     * However overdue, a first scan advances only 0 → 1, never jumping ahead — and
     * pins the program version at entry (R-BIL-04-C-1).
     */
    public function test_advance_is_monotonic_never_skips_a_level(): void
    {
        // Even with the grace clock long elapsed at entry, the engine advances exactly one
        // level per evaluation (D-2) — 0 → 1, never jumping straight to 2+.
        $this->overdueInvoice('acct_m');

        app(DunningService::class)->scan();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_m', 'current_level' => 1]); // not 2/3
        // The episode pinned the program version at entry (R-BIL-04-C-1).
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_m', 'dunning_program_ref' => 'WIK_postpaid_standard', 'dunning_program_version' => 1]);
    }

    /**
     * EXPECTATION — clearing debt reverses the applied actions and archives.
     * An episode at RESTRICTED (with a dunning-applied restriction), once cleared,
     * removes that restriction, drops to level 0 / CLEARED, and snapshots into the
     * archive (D-4 — the live row is never hard-deleted).
     */
    public function test_level2_recovery_removes_restriction_and_archives_on_clear(): void
    {
        // A single-restriction program keeps the SUB-WF RESTRICT (one-in-flight) serialization
        // deterministic; multi-code accumulation is exercised separately.
        DunningProgram::query()->create([
            'code' => 'wik_single_restrict', 'version' => 1, 'operator_code' => 'WIK', 'billing_mode' => 'POSTPAID',
            'pre_termination_review_required' => true, 'published_at' => now(), 'created_by' => 'test',
            'level_definitions' => [
                ['level' => 1, 'name' => 'WARNING', 'grace_period_days' => 7, 'action_workflow_intent' => 'WARNING_ONLY', 'action_payload' => []],
                ['level' => 2, 'name' => 'RESTRICTED', 'grace_period_days' => 7, 'action_workflow_intent' => 'RESTRICTION_ADD', 'action_payload' => ['restriction_codes' => ['OUTGOING_VOICE_BARRED']]],
                ['level' => 3, 'name' => 'SUSPENDED', 'grace_period_days' => 14, 'action_workflow_intent' => 'SUSPEND_NP', 'action_payload' => ['reason_code' => 'DUNNING_GRACE_EXPIRED']],
                ['level' => 4, 'name' => 'TERMINATED', 'grace_period_days' => 30, 'action_workflow_intent' => 'TERMINATION', 'action_payload' => []],
            ],
        ]);
        $subId = $this->postJson('/api/subscriptions', ['customer_id' => 'c1', 'account_id' => 'acct_r2', 'homepass_id' => 'h1', 'package_ref' => 'p1'])->json('subscription_id');
        Subscription::find($subId)->update(['status_code' => 'ACTIVE']);
        $this->overdueInvoice('acct_r2', 800);
        DunningState::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acct_r2', 'subscription_id' => $subId,
            'dunning_program_ref' => 'wik_single_restrict', 'dunning_program_version' => 1,
            'current_level' => 1, 'entered_level_at' => now()->subDays(8), 'entered_dunning_at' => now()->subDays(8), 'status' => 'ACTIVE',
        ]);

        $svc = app(DunningService::class);
        $svc->scan(); // advance to level 2 → apply the restriction
        $state = DunningState::query()->where('account_id', 'acct_r2')->first();
        $this->assertSame(2, $state->current_level);
        $this->assertSame(['OUTGOING_VOICE_BARRED'], $state->applied_restriction_codes);
        for ($i = 0; $i < 3; $i++) {
            Artisan::call('sophix:workflow:work', ['--once' => true]); // finalize the ADD
        }

        // Clear (debt settled) — recovery removes the dunning-applied restriction and archives.
        $svc->clear('acct_r2');
        $state->refresh();
        $this->assertSame('CLEARED', $state->status);
        $this->assertSame(0, $state->current_level);
        $this->assertSame([], $state->applied_restriction_codes);
        $this->assertDatabaseHas('dunning_state_archive', ['account_id' => 'acct_r2', 'archive_reason' => 'CLEARED_FULLY_PAID']);
    }

    /**
     * EXPECTATION — a policy edit is a NEW version; in-flight episodes keep the old.
     * Publishing a new program version retires v1 and activates v2, but an episode
     * that entered under v1 stays pinned to v1 — its escalation finishes under the
     * policy in effect when it began (R-BIL-04-C-1).
     */
    public function test_program_new_version_retires_prior_and_pins_inflight(): void
    {
        // An in-flight episode on v1.
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_v1', 'dunning_program_ref' => 'WIK_postpaid_standard', 'dunning_program_version' => 1, 'current_level' => 1, 'entered_level_at' => now(), 'status' => 'ACTIVE']);

        $this->postJson('/api/dunning-programs/WIK_postpaid_standard/new-version', [
            'level_definitions' => [
                ['level' => 1, 'grace_period_days' => 1, 'action_workflow_intent' => 'WARNING_ONLY'],
                ['level' => 2, 'grace_period_days' => 1, 'action_workflow_intent' => 'TERMINATION'],
            ],
        ])->assertCreated()->assertJsonPath('version', 2);

        // v1 retired, v2 active; the in-flight state still references v1.
        $this->assertDatabaseHas('dunning_program', ['code' => 'WIK_postpaid_standard', 'version' => 1]);
        $this->assertNotNull(DunningProgram::query()->where('code', 'WIK_postpaid_standard')->where('version', 1)->first()->retired_at);
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_v1', 'dunning_program_version' => 1]);
    }

    /**
     * EXPECTATION — prepaid enters dunning on a missed cycle, not an overdue invoice.
     * A CyclePaymentMissed event enters the prepaid account at level 1 with the
     * CYCLE_PAYMENT_MISSED trigger — the prepaid mirror of the postpaid overdue scan.
     */
    public function test_prepaid_cycle_payment_missed_enters_dunning(): void
    {
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'acct_pp',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'p', 'status_code' => 'ACTIVE', 'billing_mode' => 'PREPAID', 'currency' => 'KES',
        ]);
        $event = new OutboxEvent([
            'event_type' => 'CyclePaymentMissed', 'operator_code' => 'WIK',
            'payload' => ['subscriptionId' => $sub->subscription_id, 'amountDue' => '1200'],
        ]);
        $event->setRawAttributes(array_merge($event->getAttributes(), ['payload' => json_encode(['subscriptionId' => $sub->subscription_id, 'amountDue' => '1200'])]));

        app(DunningEventBridge::class)->handle(new OutboxEventPublished($event));

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_pp', 'current_level' => 1, 'billing_mode' => 'PREPAID', 'triggering_event_type' => 'CYCLE_PAYMENT_MISSED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionEnteredDunning']);
    }

    /**
     * EXPECTATION — termination is gated by a review window, then an admin confirm.
     * Reaching the TERMINATION level does not auto-terminate: the episode parks in
     * PENDING_TERMINATION_REVIEW (emitting the pending event). Only an admin
     * confirm-termination advances it to level 4.
     */
    public function test_termination_requires_review_then_admin_confirm(): void
    {
        DB::table('dunning_config')->insert(['operator_code' => 'WIK', 'pre_termination_review_required' => true, 'review_window_hours' => 72, 'created_at' => now(), 'updated_at' => now()]);
        $subId = $this->postJson('/api/subscriptions', ['customer_id' => 'c1', 'account_id' => 'acct_t', 'homepass_id' => 'h1', 'package_ref' => 'p1'])->json('subscription_id');
        Subscription::find($subId)->update(['status_code' => 'SUSPENDED']);
        $this->overdueInvoice('acct_t');
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_t', 'subscription_id' => $subId, 'current_level' => 3, 'entered_level_at' => now()->subDays(20), 'status' => 'ACTIVE']);

        app(DunningService::class)->scan();
        // Level 4 not auto-applied — held for review.
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_t', 'status' => 'PENDING_TERMINATION_REVIEW']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionDunningTerminationPending']);

        // Admin confirms → termination proceeds.
        $this->postJson('/api/dunning/acct_t/confirm-termination')->assertOk();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_t', 'status' => 'ACTIVE', 'current_level' => 4]);
    }

    /**
     * EXPECTATION — a voluntary pause freezes dunning.
     * Pausing sets the episode SUSPENDED_BY_PAUSE; a subsequent scan does not advance
     * it — the debt is parked, not escalated, while the customer is paused.
     */
    public function test_voluntary_pause_suspends_dunning(): void
    {
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_v', 'current_level' => 1, 'entered_level_at' => now(), 'status' => 'ACTIVE']);
        app(DunningService::class)->pauseForVoluntaryPause('acct_v');
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_v', 'status' => 'SUSPENDED_BY_PAUSE']);

        // A scan does not advance a pause-suspended episode.
        $this->overdueInvoice('acct_v');
        app(DunningService::class)->scan();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_v', 'current_level' => 1, 'status' => 'SUSPENDED_BY_PAUSE']);
    }

    /**
     * EXPECTATION — payment settles the debt and clears dunning end to end.
     * Paying the overdue balance clears the episode (status CLEARED, level 0) and
     * emits DunningCleared — the payment path drives the recovery, no manual step.
     */
    public function test_payment_clears_dunning(): void
    {
        $this->overdueInvoice('acct_p', 500);
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_p', 'current_level' => 1, 'entered_level_at' => now(), 'status' => 'ACTIVE']);

        $this->postJson('/api/payments', ['account_id' => 'acct_p', 'paid_amount' => 500, 'method' => 'MPESA'], ['Idempotency-Key' => 'pp'])
            ->assertCreated();

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_p', 'status' => 'CLEARED', 'current_level' => 0]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DunningCleared']);
    }
}
