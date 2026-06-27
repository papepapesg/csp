<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Discount\Models\Discount;
use Modules\Catalog\Discount\Models\DiscountAssignment;
use Modules\Catalog\Discount\Models\PromoCampaign;
use Modules\Catalog\Discount\Services\DiscountComputeService;
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

    public function test_campaign_mode_assignment_does_not_apply_when_campaign_is_off(): void
    {
        Discount::query()->create(['discount_id' => 'disc_p', 'operator_code' => 'WIK', 'code' => 'PROMO10',
            'name' => 'Promo', 'discount_type' => 'PERCENT', 'value' => 0.10, 'stackable' => true, 'priority' => 10, 'status' => 'ACTIVE']);

        // An ENDED campaign + a CAMPAIGN-mode assignment that is itself ACTIVE.
        $camp = PromoCampaign::query()->create(['campaign_id' => 'camp_old', 'operator_code' => 'WIK', 'code' => 'Q1',
            'name' => 'Q1', 'status' => PromoCampaign::ENDED, 'starts_at' => now()->subMonths(3), 'ends_at' => now()->subMonth()]);
        $this->assign('PROMO10', 'cust_x', mode: 'CAMPAIGN', campaignId: $camp->campaign_id);

        // The assignment is ACTIVE, but the campaign is off → no discount applies.
        $r = app(DiscountComputeService::class)->compute('WIK', 1000, ['customerId' => 'cust_x']);
        $this->assertSame(0.0, $r['totalDiscount']);

        // And the back-office read model explains WHY, instead of a misleading "active".
        $a = DiscountAssignment::query()->where('scope_ref', 'cust_x')->first();
        $this->assertSame('BLOCKED_CAMPAIGN_ENDED', $a->applicabilityState($camp)['state']);
    }

    public function test_direct_assignment_applies_regardless_of_any_campaign(): void
    {
        Discount::query()->create(['discount_id' => 'disc_d', 'operator_code' => 'WIK', 'code' => 'GOODWILL',
            'name' => 'Goodwill', 'discount_type' => 'FIXED', 'value' => 200, 'stackable' => true, 'priority' => 10, 'status' => 'ACTIVE']);
        $this->assign('GOODWILL', 'cust_y', mode: 'DIRECT');

        $r = app(DiscountComputeService::class)->compute('WIK', 1000, ['customerId' => 'cust_y']);
        $this->assertEqualsWithDelta(200.0, $r['totalDiscount'], 0.001);

        $a = DiscountAssignment::query()->where('scope_ref', 'cust_y')->first();
        $this->assertSame('APPLYING', $a->applicabilityState()['state']);
    }

    public function test_direct_beats_campaign_on_a_priority_tie_within_a_stacking_group(): void
    {
        Discount::query()->create(['discount_id' => 'disc_dir', 'operator_code' => 'WIK', 'code' => 'DIR20',
            'name' => 'Direct 20%', 'discount_type' => 'PERCENT', 'value' => 0.20, 'stackable' => false, 'priority' => 5, 'status' => 'ACTIVE']);
        Discount::query()->create(['discount_id' => 'disc_cmp', 'operator_code' => 'WIK', 'code' => 'CMP20',
            'name' => 'Campaign 20%', 'discount_type' => 'PERCENT', 'value' => 0.20, 'stackable' => false, 'priority' => 5, 'status' => 'ACTIVE']);

        $camp = PromoCampaign::query()->create(['campaign_id' => 'camp_live', 'operator_code' => 'WIK', 'code' => 'LIVE',
            'name' => 'Live', 'status' => PromoCampaign::ACTIVE, 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth()]);

        // Same stacking group + same priority: DIRECT must win the tie.
        $this->assign('CMP20', 'cust_z', mode: 'CAMPAIGN', campaignId: $camp->campaign_id, priority: 5, group: 'G1');
        $this->assign('DIR20', 'cust_z', mode: 'DIRECT', priority: 5, group: 'G1');

        $r = app(DiscountComputeService::class)->compute('WIK', 1000, ['customerId' => 'cust_z']);
        $this->assertCount(1, $r['discountLines']);
        $this->assertSame('DIR20', $r['discountLines'][0]['code']);
    }

    private function assign(string $code, string $custRef, string $mode, ?string $campaignId = null, int $priority = 100, ?string $group = null): void
    {
        DiscountAssignment::query()->create([
            'assignment_id' => 'dasg_'.uniqid(), 'operator_code' => 'WIK', 'discount_code' => $code,
            'scope' => 'CUSTOMER', 'scope_ref' => $custRef, 'scope_type' => 'CUSTOMER', 'scope_ref_id' => $custRef,
            'customer_id' => $custRef, 'assignment_mode' => $mode, 'campaign_id' => $campaignId,
            'assignment_priority' => $priority, 'stacking_group_code' => $group,
            'status' => DiscountAssignment::ACTIVE, 'active' => true, 'valid_from' => now()->subDay()->toDateString(),
        ]);
    }
}
