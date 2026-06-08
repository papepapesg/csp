<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\OperationConfigSeeder;
use Modules\Subscription\Database\Seeders\PerOperationConfigSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * DD_SUB-WF-SUSPEND-NP-01: the non-payment suspension trigger is BILLING_INTERNAL
 * only (R-T-1) and records the dunning context on the pause-history row (R-M-2).
 */
class SubscriptionSuspendNpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(OperationConfigSeeder::class); // maps SUSPEND_NP -> sub-suspend-np
        $this->seed(PerOperationConfigSeeder::class);
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function activeSubscription(): string
    {
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_1',
            'status_code' => 'ACTIVE', 'currency' => 'KES',
        ]);

        return $sub->subscription_id;
    }

    private function payload(): array
    {
        return [
            'dunningReasonCode' => 'DUNNING_ESCALATION_T3',
            'dunningCycleReference' => 'bil04-wave-2026-06-15-L3',
            'outstandingDebtAmount' => 7500.00,
            'outstandingDebtCurrency' => 'KES',
            'dunningEscalationLevel' => 3,
        ];
    }

    public function test_non_billing_internal_actor_is_forbidden(): void
    {
        // SUPER_ADMIN has subscription.manage but not the BILLING_INTERNAL role.
        $this->actAs('SUPER_ADMIN');
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/suspend-np", $this->payload(), ['Idempotency-Key' => 'sn-403'])
            ->assertStatus(403)
            ->assertJsonPath('errorCode', 'UNAUTHORIZED_TRIGGER');

        $this->assertSame('ACTIVE', Subscription::find($id)->status_code);
    }

    public function test_billing_internal_suspends_and_records_dunning_context(): void
    {
        $this->actAs('BILLING_INTERNAL');
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/suspend-np", $this->payload(), ['Idempotency-Key' => 'sn-ok'])
            ->assertStatus(202);
        Artisan::call('sophix:workflow:work', ['--once' => true]);

        $this->assertSame('SUSPENDED', Subscription::find($id)->status_code);
        // R-M-2: pause-history row carries the SUSPEND_NP marker + dunning context.
        $this->assertDatabaseHas('subscription_pause_history', [
            'subscription_id' => $id, 'origin_intent' => 'SUSPEND_NP', 'system_managed' => true,
            'dunning_cycle_reference' => 'bil04-wave-2026-06-15-L3', 'outstanding_debt_amount' => 7500.00,
            'actual_resume_at' => null,
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionSuspendedForNonPayment']);
    }

    public function test_missing_dunning_context_is_rejected(): void
    {
        $this->actAs('BILLING_INTERNAL');
        $id = $this->activeSubscription();

        $this->postJson("/api/subscriptions/{$id}/suspend-np", ['dunningReasonCode' => 'X'], ['Idempotency-Key' => 'sn-422'])
            ->assertStatus(422);
    }

    public function test_pause_and_suspend_np_config_catalogs_are_seeded(): void
    {
        $this->assertDatabaseHas('subscription_pause_config', ['operator_code' => 'WIK', 'customer_self_service_enabled' => true]);
        $this->assertDatabaseHas('subscription_pause_config', ['operator_code' => 'WUG', 'customer_self_service_enabled' => false]);
        $this->assertDatabaseHas('subscription_suspend_np_config', ['operator_code' => 'WIK', 'debt_amount_warning_threshold' => 5000.00, 'debt_amount_warning_currency' => 'KES']);
        $this->assertDatabaseHas('subscription_suspend_np_config', ['operator_code' => 'WTZ', 'debt_amount_warning_threshold' => null]);
    }
}
