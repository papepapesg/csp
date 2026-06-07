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
