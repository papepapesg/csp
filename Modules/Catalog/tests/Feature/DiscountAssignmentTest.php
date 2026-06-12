<?php

namespace Modules\Catalog\Tests\Feature;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Models\DiscountAssignment;
use Tests\TestCase;

/**
 * SIP-03 discount assignment lifecycle: create/activate, EM-CFG-04 approval gating, cancel
 * (future-only), the runtime effective-query, and DIS-OP-01 honouring status + validity +
 * stacking groups at compute time.
 */
class DiscountAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        $this->postJson('/api/discounts', ['code' => 'RET10', 'name' => 'Retention 10%', 'discount_type' => 'PERCENT', 'value' => 0.10, 'stackable' => true, 'priority' => 10])->assertCreated();
    }

    public function test_create_assignment_activates_without_approval(): void
    {
        $res = $this->postJson('/api/discount-assignments', [
            'discountCode' => 'RET10', 'scopeType' => 'SUBSCRIPTION', 'scopeRefId' => 'SUB-1',
            'subscriptionId' => 'SUB-1', 'reasonCode' => 'RETENTION_SAVE', 'sourceChannel' => 'BACKOFFICE',
            'validFrom' => '2026-06-01', 'validTo' => '2026-08-31',
        ], ['Idempotency-Key' => 'da-1'])->assertCreated();

        $res->assertJsonPath('status', 'ACTIVE')->assertJsonPath('approvalRequired', false);
        $id = $res->json('assignmentId');
        $this->assertDatabaseHas('discount_assignment', ['assignment_id' => $id, 'status' => 'ACTIVE', 'scope_type' => 'SUBSCRIPTION']);
        $this->assertDatabaseHas('discount_assignment_status_history', ['assignment_id' => $id, 'new_status' => 'ACTIVE']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DiscountAssignmentActivated']);
    }

    public function test_duplicate_active_assignment_is_blocked(): void
    {
        $payload = ['discountCode' => 'RET10', 'scopeType' => 'SUBSCRIPTION', 'scopeRefId' => 'SUB-2'];
        $this->postJson('/api/discount-assignments', $payload, ['Idempotency-Key' => 'd1'])->assertCreated();
        $this->postJson('/api/discount-assignments', $payload, ['Idempotency-Key' => 'd2'])->assertStatus(409); // R-DA-05
    }

    public function test_high_value_assignment_requires_em_cfg_04_approval(): void
    {
        ApprovalDefinition::query()->create([
            'definition_id' => Id::make('appd'), 'operator_code' => 'WIK', 'entity_type' => 'DISCOUNT_ASSIGNMENT',
            'action' => 'DISCOUNT_ASSIGNMENT_CREATE', 'threshold_amount' => 1000, 'approver_roles' => ['SUPER_ADMIN'], 'required_approvals' => 1, 'active' => true,
        ]);

        $res = $this->postJson('/api/discount-assignments', [
            'discountCode' => 'RET10', 'scopeType' => 'CUSTOMER', 'scopeRefId' => 'CUS-1', 'estimatedValue' => 5000,
        ], ['Idempotency-Key' => 'da-hv'])->assertCreated();

        $res->assertJsonPath('status', 'PENDING_APPROVAL')->assertJsonPath('approvalRequired', true);
        $id = $res->json('assignmentId');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DiscountAssignmentApprovalRequired']);

        // It must NOT apply at runtime while pending.
        $this->assertSame('PENDING_APPROVAL', DiscountAssignment::find($id)->status);
        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'CUS-1'])->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, $r['totalDiscount'], 0.001);

        // The EM-CFG-04 callback approves → activates.
        $this->postJson("/api/discount-assignments/{$id}/approval-outcome", ['outcome' => 'APPROVED'])
            ->assertOk()->assertJsonPath('status', 'ACTIVE');
        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'CUS-1'])->assertOk()->json();
        $this->assertEqualsWithDelta(100.0, $r['totalDiscount'], 0.001); // now applies
    }

    public function test_cancel_stops_future_runtime(): void
    {
        $id = $this->postJson('/api/discount-assignments', ['discountCode' => 'RET10', 'scopeType' => 'CUSTOMER', 'scopeRefId' => 'CUS-9'], ['Idempotency-Key' => 'da-c'])->json('assignmentId');

        $this->postJson("/api/discount-assignments/{$id}/cancel", ['reasonCode' => 'CUSTOMER_NO_LONGER_ELIGIBLE'])
            ->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DiscountAssignmentCancelled']);

        // No longer applied at runtime.
        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'CUS-9'])->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, $r['totalDiscount'], 0.001);
    }

    public function test_validity_window_gates_runtime_application(): void
    {
        // An assignment whose window is entirely in the future is not applied today.
        $this->postJson('/api/discount-assignments', [
            'discountCode' => 'RET10', 'scopeType' => 'CUSTOMER', 'scopeRefId' => 'CUS-F',
            'validFrom' => now()->addMonth()->toDateString(), 'validTo' => now()->addMonths(2)->toDateString(),
        ], ['Idempotency-Key' => 'da-f'])->assertCreated();

        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'CUS-F'])->assertOk()->json();
        $this->assertEqualsWithDelta(0.0, $r['totalDiscount'], 0.001);
    }

    public function test_stacking_group_keeps_only_highest_priority(): void
    {
        $this->postJson('/api/discounts', ['code' => 'BIGGER', 'name' => 'Bigger', 'discount_type' => 'PERCENT', 'value' => 0.20, 'stackable' => true, 'priority' => 5])->assertCreated();
        // Two assignments in the same stacking group — only the higher-priority (lower number) applies.
        $this->postJson('/api/discount-assignments', ['discountCode' => 'RET10', 'scopeType' => 'CUSTOMER', 'scopeRefId' => 'CUS-S', 'priority' => 50, 'stackingGroupCode' => 'RECURRING_MRC'], ['Idempotency-Key' => 's1'])->assertCreated();
        $this->postJson('/api/discount-assignments', ['discountCode' => 'BIGGER', 'scopeType' => 'CUSTOMER', 'scopeRefId' => 'CUS-S', 'priority' => 10, 'stackingGroupCode' => 'RECURRING_MRC'], ['Idempotency-Key' => 's2'])->assertCreated();

        $r = $this->postJson('/api/discounts/compute', ['baseAmount' => 1000, 'customerId' => 'CUS-S'])->assertOk()->json();
        // Only BIGGER (priority 10) survives the group → 20% = 200, not 300.
        $this->assertEqualsWithDelta(200.0, $r['totalDiscount'], 0.001);
        $this->assertCount(1, $r['discountLines']);
    }

    public function test_effective_query_returns_active_in_window(): void
    {
        $this->postJson('/api/discount-assignments', ['discountCode' => 'RET10', 'scopeType' => 'SUBSCRIPTION', 'scopeRefId' => 'SUB-E', 'subscriptionId' => 'SUB-E'], ['Idempotency-Key' => 'da-e'])->assertCreated();

        $this->getJson('/api/discount-assignments/effective?subscriptionId=SUB-E')->assertOk()
            ->assertJsonPath('assignments.0.discount_code', 'RET10');
    }
}
