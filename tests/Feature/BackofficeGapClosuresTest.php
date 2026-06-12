<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The backoffice "honest gaps" closed after #47: payment allocation breakdown, the platform-wide
 * restriction monitor, and the cycle-close run monitor — each now a real read endpoint.
 */
class BackofficeGapClosuresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $u = User::factory()->create(['operator_code' => 'WIK']);
        $u->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($u);
    }

    public function test_cycle_close_run_monitor_is_exposed(): void
    {
        $this->getJson('/api/cycle-close-runs')->assertOk()->assertJsonStructure(['items']);
    }

    public function test_restriction_monitor_lists_restricted_subscriptions(): void
    {
        \Modules\Subscription\Models\Subscription::query()->create([
            'subscription_id' => \App\Foundation\Support\Id::make('sub'), 'operator_code' => 'WIK',
            'customer_id' => 'cust_x', 'account_id' => 'acct_x', 'package_ref' => 'PKG', 'homepass_id' => 'hp_x', 'status_code' => 'ACTIVE',
            'active_restrictions' => [['restrictionCode' => 'DATA_THROTTLED_LOW']],
        ]);
        // An unrestricted subscription must NOT appear.
        \Modules\Subscription\Models\Subscription::query()->create([
            'subscription_id' => \App\Foundation\Support\Id::make('sub'), 'operator_code' => 'WIK',
            'customer_id' => 'cust_y', 'account_id' => 'acct_y', 'package_ref' => 'PKG', 'homepass_id' => 'hp_x', 'status_code' => 'ACTIVE',
            'active_restrictions' => [],
        ]);

        $res = $this->getJson('/api/subscription-restrictions')->assertOk();
        $items = collect($res->json('items'));
        $this->assertCount(1, $items);
        $this->assertSame('DATA_THROTTLED_LOW', $items[0]['restrictions'][0]['restrictionCode']);
    }

    public function test_payment_show_returns_allocations_relation(): void
    {
        $payment = \Modules\Billing\Models\PaymentLedger::query()->create([
            'payment_id' => \App\Foundation\Support\Id::make('pay'), 'operator_code' => 'WIK',
            'account_id' => 'acct_x', 'paid_amount' => 500, 'unallocated_amount' => 0,
            'method' => 'MPESA', 'payment_reference' => 'MPESA-1', 'received_at' => now(),
        ]);

        $this->getJson("/api/payments/{$payment->payment_id}")->assertOk()
            ->assertJsonPath('payment_id', $payment->payment_id)
            ->assertJsonStructure(['allocations']);
    }
}
