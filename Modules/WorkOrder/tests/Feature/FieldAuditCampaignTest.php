<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\WorkOrder\Database\Seeders\FieldAuditPolicySeeder;
use Modules\WorkOrder\Models\FieldAuditDiscrepancy;
use Modules\WorkOrder\Models\FieldAuditExpectedItem;
use Modules\WorkOrder\Models\FieldAuditObservation;
use Modules\WorkOrder\Models\FieldAuditTask;
use Modules\WorkOrder\Models\WorkOrder;
use Tests\TestCase;

/**
 * FA-01/02/03 campaign/task model: expected-vs-observed comparison raising typed discrepancies,
 * Drools-driven severity + routing, EM-CFG-04 gating for risky OSR corrections, and the clean
 * audit auto-close. One capability, audit_type-driven.
 */
class FieldAuditCampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(FieldAuditPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function taskWithExpected(string $serial = 'ONT123456'): string
    {
        return $this->postJson('/api/field-audit-tasks', [
            'auditType' => 'EQUIPMENT', 'taskType' => 'CUSTOMER_PREMISES', 'customerId' => 'CUS-1', 'assignedToUserId' => 'tech-1',
            'sourceEventRef' => 'fat-'.$serial,
            'expectedItems' => [['equipmentInstanceId' => 'inst-1', 'serialNumber' => $serial, 'expectedLocationRef' => 'CUS-1']],
        ], ['Idempotency-Key' => 'k-'.$serial])->assertCreated()->json('audit_task_id');
    }

    public function test_clean_observation_closes_the_task(): void
    {
        $id = $this->taskWithExpected();

        $this->postJson("/api/field-audit-tasks/{$id}/observations", [
            'observedSerialNumber' => 'ONT123456', 'presenceStatus' => 'PRESENT', 'conditionStatus' => 'WORKING', 'observedLocationRef' => 'CUS-1',
        ])->assertCreated();

        $this->assertSame('CLOSED', FieldAuditTask::find($id)->status);
        $this->assertSame(0, FieldAuditDiscrepancy::where('audit_task_id', $id)->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'FieldAuditTaskClosed']);
    }

    public function test_missing_equipment_raises_high_discrepancy_routed_to_rma(): void
    {
        $id = $this->taskWithExpected();

        $this->postJson("/api/field-audit-tasks/{$id}/observations", [
            'expectedItemId' => FieldAuditExpectedItem::where('audit_task_id', $id)->first()->expected_item_id,
            'presenceStatus' => 'MISSING',
        ])->assertCreated();

        $d = FieldAuditDiscrepancy::where('audit_task_id', $id)->first();
        $this->assertSame('MISSING', $d->discrepancy_type);
        $this->assertSame('HIGH', $d->severity);
        $this->assertSame('CREATE_RMA_RECOVERY', $d->route_action);
        $this->assertSame('ACTION_CREATED', $d->status);
        $this->assertSame('OSR_RMA', $d->routed_ref_type);
        $this->assertSame('DISCREPANCY_OPEN', FieldAuditTask::find($id)->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'FieldAuditDiscrepancyOpened']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'FieldAuditDiscrepancyRouted']);
    }

    public function test_wrong_serial_requires_em_cfg_04_approval_before_osr_correction(): void
    {
        $id = $this->taskWithExpected('ONT-AAA');

        $this->postJson("/api/field-audit-tasks/{$id}/observations", [
            'observedSerialNumber' => 'ONT-BBB', 'presenceStatus' => 'PRESENT', 'conditionStatus' => 'WORKING',
        ])->assertCreated();

        $d = FieldAuditDiscrepancy::where('audit_task_id', $id)->first();
        $this->assertSame('WRONG_SERIAL', $d->discrepancy_type);
        $this->assertSame('REQUEST_OSR_CORRECTION', $d->route_action);
        $this->assertSame('PENDING_APPROVAL', $d->status); // risky route gated by EM-CFG-04
        $this->assertNotNull($d->approval_request_id);
        $this->assertDatabaseHas('approval_request', ['entity_type' => 'FIELD_AUDIT_DISCREPANCY', 'status' => 'PENDING']);

        // Approval callback → the OSR correction is now requested.
        $this->postJson("/api/field-audit-discrepancies/{$d->discrepancy_id}/approval-outcome", ['outcome' => 'APPROVED'])
            ->assertOk()->assertJsonPath('status', 'ACTION_CREATED');
        $this->assertSame('OSR_CORRECTION', $d->refresh()->routed_ref_type);
    }

    public function test_create_work_order_request_produces_a_wo_backed_task(): void
    {
        // FA-01 §6 step 4: createWorkOrder=true -> WO-01 builds the field-audit work order.
        $id = $this->postJson('/api/field-audit-tasks', [
            'auditType' => 'EQUIPMENT', 'taskType' => 'CUSTOMER_PREMISES', 'customerId' => 'CUS-1', 'accountId' => 'ACC-1',
            'subscriptionId' => 'SUB-1', 'homepassId' => 'HP-1', 'createWorkOrder' => true, 'sourceEventRef' => 'fat-wo',
            'expectedItems' => [['equipmentInstanceId' => 'inst-1', 'serialNumber' => 'ONT-1', 'expectedLocationRef' => 'CUS-1']],
        ], ['Idempotency-Key' => 'k-wo'])->assertCreated()->json('audit_task_id');

        // The orphan event is now consumed: dispatching the outbox creates the WO.
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();

        $task = FieldAuditTask::find($id);
        $this->assertNotNull($task->wo_id);
        $this->assertSame('ASSIGNED', $task->status); // FA-01 stores wo_id and moves the task to ASSIGNED
        $this->assertDatabaseHas('work_order', [
            'work_order_id' => $task->wo_id, 'type' => 'FIELD_AUDIT', 'source_type' => 'FIELD_AUDIT',
            'source_ref' => $id, 'account_id' => 'ACC-1', 'subscription_id' => 'SUB-1', 'customer_id' => 'CUS-1',
        ]);

        // Re-dispatching does not create a second WO (idempotent on wo_id).
        $woId = $task->wo_id;
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();
        $this->assertSame($woId, FieldAuditTask::find($id)->wo_id);
        $this->assertSame(1, WorkOrder::where('source_ref', $id)->count());
    }

    public function test_observation_is_idempotent_by_offline_ref(): void
    {
        $id = $this->taskWithExpected();
        $payload = ['observedSerialNumber' => 'ONT123456', 'presenceStatus' => 'PRESENT', 'offlineClientRef' => 'mob-1'];

        $a = $this->postJson("/api/field-audit-tasks/{$id}/observations", $payload)->json('observation_id');
        $b = $this->postJson("/api/field-audit-tasks/{$id}/observations", $payload)->json('observation_id');

        $this->assertSame($a, $b);
        $this->assertSame(1, FieldAuditObservation::where('audit_task_id', $id)->count());
    }

    public function test_task_creation_is_idempotent_by_source_event(): void
    {
        $a = $this->taskWithExpected('SAME');
        $b = $this->taskWithExpected('SAME');
        $this->assertSame($a, $b);
    }
}
