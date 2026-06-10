<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

/** WO-01: SLA due-time capture, skills-filtered auto-assign, master/sub linkage. */
class WorkOrderSlaSkillsTest extends TestCase
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
            'operator_code' => 'WIK', 'type' => 'INSTALL', 'priority' => 'URGENT', 'account_id' => 'acc_1',
        ], $extra));
    }

    public function test_sla_due_at_is_captured_at_creation_and_first_response_on_assign(): void
    {
        $wo = $this->wo(['priority' => 'URGENT']); // 4h SLA
        $this->assertNotNull($wo->sla_due_at);
        $this->assertEqualsWithDelta(now()->addHours(4)->timestamp, $wo->sla_due_at->timestamp, 60);

        app(WorkOrderService::class)->assign($wo, ['assigned_technician_id' => 'stf_x']);
        $this->assertNotNull($wo->fresh()->first_response_at);
    }

    public function test_skills_filtered_auto_assign_picks_a_capable_technician(): void
    {
        // Two staff: one lacks FIBER, one has it.
        DB::table('staff_member')->insert([
            ['staff_id' => 'stf_1', 'operator_code' => 'WIK', 'name' => 'A', 'role' => 'TECHNICIAN', 'skills' => json_encode(['COPPER']), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
            ['staff_id' => 'stf_2', 'operator_code' => 'WIK', 'name' => 'B', 'role' => 'TECHNICIAN', 'skills' => json_encode(['COPPER', 'FIBER']), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $wo = $this->wo(['required_skills' => ['FIBER']]);
        $assigned = app(WorkOrderService::class)->autoAssign($wo);

        $this->assertSame('stf_2', $assigned->assigned_technician_id);
        $this->assertSame(WorkOrder::ASSIGNED, $assigned->status);
    }

    public function test_no_capable_staff_leaves_it_pending(): void
    {
        $wo = $this->wo(['required_skills' => ['SATELLITE']]);
        $this->assertNull(app(WorkOrderService::class)->autoAssign($wo));
        $this->assertSame(WorkOrder::PENDING, $wo->fresh()->status);
    }

    public function test_sub_work_order_links_to_master(): void
    {
        $master = $this->wo();
        $sub = $this->wo();
        app(WorkOrderService::class)->linkToMaster($sub, $master->work_order_id);
        $this->assertDatabaseHas('work_order', ['work_order_id' => $sub->work_order_id, 'master_wo_id' => $master->work_order_id, 'link_type' => 'PARENT_CHILD']);
    }
}
