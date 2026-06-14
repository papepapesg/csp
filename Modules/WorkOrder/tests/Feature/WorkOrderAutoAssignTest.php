<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

/**
 * WO-01 dispatch router: auto-assign prefers an OUTSOURCED contractor with region coverage +
 * skills + availability (EM-02), and books its slot; falls back to in-house staff; leaves the
 * WO PENDING when neither is available.
 */
class WorkOrderAutoAssignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function wo(array $extra = []): WorkOrder
    {
        return app(WorkOrderService::class)->create(array_merge([
            'operator_code' => 'WIK', 'type' => 'INSTALLATION', 'kind' => 'INSTALLATION',
            'priority' => 'NORMAL', 'tech_region_id' => 'tr_1', 'required_skills' => ['FIBER'],
            'scheduled_at' => now()->setTime(10, 0),
        ], $extra));
    }

    /** Seed a contractor that covers (tr_1, INSTALLATION), holds FIBER, and has an open all-week slot. */
    private function seedAvailableContractor(string $contractorId = 'tcon_1', int $maxConcurrent = 3): string
    {
        DB::table('contractor')->insert([
            'contractor_id' => $contractorId, 'operator_code' => 'WIK', 'code' => 'C1',
            'name' => 'Acme Fiber', 'type' => 'COMPANY', 'status' => 'ACTIVE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contractor_region_scope')->insert([
            'coverage_id' => 'cov_1', 'operator_code' => 'WIK', 'contractor_id' => $contractorId,
            'tech_region_id' => 'tr_1', 'service_scope' => 'INSTALLATION', 'coverage_role' => 'PRIMARY',
            'effective_from' => now()->subDay()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contractor_region_skill')->insert([
            'contractor_id' => $contractorId, 'tech_region_id' => 'tr_1', 'operator_code' => 'WIK',
            'skill_code' => 'FIBER', 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('contractor_availability_slot')->insert([
            'slot_id' => 'slot_1', 'operator_code' => 'WIK', 'contractor_id' => $contractorId,
            'tech_region_id' => 'tr_1', 'service_scope' => 'INSTALLATION', 'day_of_week' => 'ALL_WEEK',
            'hour_start' => '00:00:00', 'hour_end' => '23:59:59', 'timezone' => 'Africa/Nairobi',
            'max_concurrent' => $maxConcurrent, 'emergency_only' => false, 'active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $contractorId;
    }

    public function test_auto_assign_prefers_an_available_contractor_and_books_the_slot(): void
    {
        $contractorId = $this->seedAvailableContractor();
        $wo = $this->wo();

        $assigned = app(WorkOrderService::class)->autoAssign($wo);

        $this->assertSame($contractorId, $assigned->contractor_id);
        $this->assertNull($assigned->assigned_technician_id);          // assigned at contractor level
        $this->assertSame(WorkOrder::ASSIGNED, $assigned->status);
        // The contractor's capacity was committed against the slot for the scheduled day.
        $this->assertDatabaseHas('contractor_slot_commitment', [
            'slot_id' => 'slot_1', 'wo_id' => $wo->work_order_id, 'status' => 'ACTIVE',
        ]);
    }

    public function test_falls_back_to_in_house_staff_when_no_contractor_available(): void
    {
        // No contractor seeded → contractor resolve returns empty → staff fallback.
        DB::table('staff_member')->insert([
            'staff_id' => 'stf_1', 'operator_code' => 'WIK', 'name' => 'Tech', 'role' => 'TECHNICIAN',
            'skills' => json_encode(['FIBER']), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $assigned = app(WorkOrderService::class)->autoAssign($this->wo());

        $this->assertSame('stf_1', $assigned->assigned_technician_id);
        $this->assertNull($assigned->contractor_id);
        $this->assertSame(WorkOrder::ASSIGNED, $assigned->status);
    }

    public function test_leaves_pending_when_neither_contractor_nor_staff_available(): void
    {
        $wo = $this->wo(['required_skills' => ['SATELLITE']]);
        $this->assertNull(app(WorkOrderService::class)->autoAssign($wo));
        $this->assertSame(WorkOrder::PENDING, $wo->fresh()->status);
    }

    public function test_skips_contractor_path_when_no_tech_region(): void
    {
        $this->seedAvailableContractor();
        DB::table('staff_member')->insert([
            'staff_id' => 'stf_2', 'operator_code' => 'WIK', 'name' => 'Tech2', 'role' => 'TECHNICIAN',
            'skills' => json_encode(['FIBER']), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // No tech_region_id → contractor path skipped → staff used even though a contractor exists.
        $assigned = app(WorkOrderService::class)->autoAssign($this->wo(['tech_region_id' => null]));
        $this->assertSame('stf_2', $assigned->assigned_technician_id);
        $this->assertNull($assigned->contractor_id);
    }
}
