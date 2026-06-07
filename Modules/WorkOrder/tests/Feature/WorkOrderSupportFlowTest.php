<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Modules\WorkOrder\Database\Seeders\WoSupportSeeder;
use Modules\WorkOrder\Models\WorkOrder;
use Tests\TestCase;

class WorkOrderSupportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(WoSupportSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function supportWorkOrder(string $jobType = 'GP3'): string
    {
        return $this->postJson('/api/work-orders', [
            'type' => 'SUPPORT', 'kind' => 'SUPPORT', 'job_type_code' => $jobType,
            'customer_id' => 'cust_1', 'account_id' => 'acct_1', 'homepass_id' => 'hp_1',
        ], ['Idempotency-Key' => 'wo-'.$jobType])->assertCreated()->json('work_order_id');
    }

    public function test_resolved_path_completes_support_wo(): void
    {
        $id = $this->supportWorkOrder('GP3');

        $this->postJson("/api/work-orders/{$id}/support-flow")->assertStatus(202);
        $this->drain(); // warranty -> site-visit -> gateway -> await (user task parked)
        $this->assertSame('PENDING', WorkOrder::find($id)->status); // not yet finalized

        $this->postJson("/api/work-orders/{$id}/resolve", ['final_reason' => 'RESOLVED', 'bindings' => [['serial' => 'SN1']]])
            ->assertStatus(202);
        $this->drain(); // resolution gate -> bindings -> finalize -> end

        $wo = WorkOrder::find($id);
        $this->assertSame('FINALIZED', $wo->status);
        $this->assertSame('RESOLVED', $wo->final_reason);
        $this->assertNotNull($wo->warranty_until);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderSupportCompleted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderEquipmentBindingsRecorded']);
    }

    public function test_escalation_spawns_qcs_work_order(): void
    {
        $id = $this->supportWorkOrder('GP3');
        $this->postJson("/api/work-orders/{$id}/support-flow")->assertStatus(202);
        $this->drain();

        $this->postJson("/api/work-orders/{$id}/resolve", ['final_reason' => 'COULD_NOT_RESOLVE_ESCALATED'])->assertStatus(202);
        $this->drain();

        $wo = WorkOrder::find($id);
        $this->assertSame('FINALIZED', $wo->status);
        $this->assertTrue($wo->escalation_candidate);

        // A QCS WO was spawned, linked to the original via master_wo_id.
        $qcs = WorkOrder::query()->where('master_wo_id', $id)->where('job_type_code', 'QCS')->first();
        $this->assertNotNull($qcs);
        $this->assertSame('ctr_NOC_MAINT', $qcs->team_id);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderEscalationCandidate']);
    }

    public function test_no_site_visit_job_type_still_resolves(): void
    {
        $id = $this->supportWorkOrder('HS4'); // requires_site_visit = false
        $this->postJson("/api/work-orders/{$id}/support-flow")->assertStatus(202);
        $this->drain();

        $this->postJson("/api/work-orders/{$id}/resolve", ['final_reason' => 'RESOLVED'])->assertStatus(202);
        $this->drain();

        $this->assertSame('FINALIZED', WorkOrder::find($id)->status);
    }
}
