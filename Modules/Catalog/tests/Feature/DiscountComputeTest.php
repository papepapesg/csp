<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DiscountComputeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_stackable_discounts_accumulate_and_nonstackable_wins_alone(): void
    {
        // Two stackable percent discounts assigned to a customer.
        $this->postJson('/api/discounts', ['code' => 'LOYALTY10', 'name' => 'Loyalty', 'discount_type' => 'PERCENT', 'value' => 0.10, 'stackable' => true, 'priority' => 10])->assertCreated();
        $this->postJson('/api/discounts', ['code' => 'WELCOME5', 'name' => 'Welcome', 'discount_type' => 'FIXED', 'value' => 100, 'stackable' => true, 'priority' => 20])->assertCreated();
        $this->postJson('/api/discounts/assign', ['discount_code' => 'LOYALTY10', 'scope' => 'CUSTOMER', 'scope_ref' => 'cust_1'])->assertCreated();
        $this->postJson('/api/discounts/assign', ['discount_code' => 'WELCOME5', 'scope' => 'CUSTOMER', 'scope_ref' => 'cust_1'])->assertCreated();

        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'cust_1'])->assertOk()->json();
        // 10% of 1000 = 100, then fixed 100 => 200 total, net 800.
        $this->assertEqualsWithDelta(200.0, $r['totalDiscount'], 0.001);
        $this->assertEqualsWithDelta(800.0, $r['netAmount'], 0.001);
    }

    public function test_nonstackable_discount_applies_alone(): void
    {
        $this->postJson('/api/discounts', ['code' => 'BIG50', 'name' => 'Half off', 'discount_type' => 'PERCENT', 'value' => 0.50, 'stackable' => false, 'priority' => 5])->assertCreated();
        $this->postJson('/api/discounts', ['code' => 'EXTRA', 'name' => 'Extra', 'discount_type' => 'FIXED', 'value' => 100, 'stackable' => true, 'priority' => 50])->assertCreated();
        $this->postJson('/api/discounts/assign', ['discount_code' => 'BIG50', 'scope' => 'ALL'])->assertCreated();
        $this->postJson('/api/discounts/assign', ['discount_code' => 'EXTRA', 'scope' => 'ALL'])->assertCreated();

        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000])->assertOk()->json();
        // BIG50 (priority 5, non-stackable) wins alone: 500 off.
        $this->assertEqualsWithDelta(500.0, $r['totalDiscount'], 0.001);
        $this->assertCount(1, $r['discountLines']);
    }
}
