<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Database\Seeders\DunningPolicySeeder;
use Modules\Billing\Models\DunningState;
use Modules\Billing\Models\Invoice;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

class DunningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
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

    public function test_scan_advances_level_zero_to_warning(): void
    {
        $this->overdueInvoice('acct_w');

        $this->postJson('/api/dunning/run')->assertOk()->assertJsonPath('advanced', 1);

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_w', 'current_level' => 1]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DunningStageAdvanced']);
    }

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

    public function test_advance_is_monotonic_never_skips_a_level(): void
    {
        // A misconfigured policy that jumps 0 → 3 may only advance one level.
        \Modules\Rules\Models\DecisionTable::query()->create([
            'table_id' => \App\Foundation\Support\Id::make('dt'), 'rule_set' => 'rules.billing.dunning', 'operator_code' => 'WIK', 'version' => 2,
            'name' => 'jumpy', 'hit_policy' => 'FIRST',
            'rules' => [['ruleId' => 'R-J', 'when' => [['var' => 'currentLevel', 'op' => 'eq', 'value' => 0]], 'then' => ['nextLevel' => 3, 'action' => 'SUSPEND']]],
            'default_output' => [], 'status' => 'DEPLOYED',
        ]);
        $this->overdueInvoice('acct_m');

        app(\Modules\Billing\Services\DunningService::class)->scan();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_m', 'current_level' => 1]); // not 3
    }

    public function test_prepaid_cycle_payment_missed_enters_dunning(): void
    {
        $sub = Subscription::query()->create([
            'subscription_id' => \App\Foundation\Support\Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'acct_pp',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'p', 'status_code' => 'ACTIVE', 'billing_mode' => 'PREPAID', 'currency' => 'KES',
        ]);
        $event = new \App\Foundation\Events\Outbox\OutboxEvent([
            'event_type' => 'CyclePaymentMissed', 'operator_code' => 'WIK',
            'payload' => ['subscriptionId' => $sub->subscription_id, 'amountDue' => '1200'],
        ]);
        $event->setRawAttributes(array_merge($event->getAttributes(), ['payload' => json_encode(['subscriptionId' => $sub->subscription_id, 'amountDue' => '1200'])]));

        app(\Modules\Billing\Listeners\DunningEventBridge::class)->handle(new \App\Foundation\Events\OutboxEventPublished($event));

        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_pp', 'current_level' => 1, 'billing_mode' => 'PREPAID', 'triggering_event_type' => 'CyclePaymentMissed']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionEnteredDunning']);
    }

    public function test_termination_requires_review_then_admin_confirm(): void
    {
        \Illuminate\Support\Facades\DB::table('dunning_config')->insert(['operator_code' => 'WIK', 'pre_termination_review_required' => true, 'review_window_hours' => 72, 'created_at' => now(), 'updated_at' => now()]);
        $subId = $this->postJson('/api/subscriptions', ['customer_id' => 'c1', 'account_id' => 'acct_t', 'homepass_id' => 'h1', 'package_ref' => 'p1'])->json('subscription_id');
        Subscription::find($subId)->update(['status_code' => 'SUSPENDED']);
        $this->overdueInvoice('acct_t');
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_t', 'subscription_id' => $subId, 'current_level' => 3, 'entered_level_at' => now()->subDays(20), 'status' => 'ACTIVE']);

        app(\Modules\Billing\Services\DunningService::class)->scan();
        // Level 4 not auto-applied — held for review.
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_t', 'status' => 'PENDING_TERMINATION_REVIEW']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionDunningTerminationPending']);

        // Admin confirms → termination proceeds.
        $this->postJson('/api/dunning/acct_t/confirm-termination')->assertOk();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_t', 'status' => 'ACTIVE', 'current_level' => 4]);
    }

    public function test_voluntary_pause_suspends_dunning(): void
    {
        DunningState::query()->create(['operator_code' => 'WIK', 'account_id' => 'acct_v', 'current_level' => 1, 'entered_level_at' => now(), 'status' => 'ACTIVE']);
        app(\Modules\Billing\Services\DunningService::class)->pauseForVoluntaryPause('acct_v');
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_v', 'status' => 'SUSPENDED_BY_PAUSE']);

        // A scan does not advance a pause-suspended episode.
        $this->overdueInvoice('acct_v');
        app(\Modules\Billing\Services\DunningService::class)->scan();
        $this->assertDatabaseHas('dunning_state', ['account_id' => 'acct_v', 'current_level' => 1, 'status' => 'SUSPENDED_BY_PAUSE']);
    }

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
