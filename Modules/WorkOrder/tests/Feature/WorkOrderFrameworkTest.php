<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\WorkOrder\Database\Seeders\WoFrameworkSeeder;
use Modules\WorkOrder\Models\WoFinalizationRequirement;
use Modules\WorkOrder\Models\WorkOrder;
use Tests\TestCase;

/**
 * WO-01-FRAMEWORK alignment: reassign (§1.7), structured notes (§1.3), and the
 * 2-step finalize with the config-driven checklist (§3/§4.4).
 */
class WorkOrderFrameworkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WoFrameworkSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function supportWo(): string
    {
        return $this->postJson('/api/work-orders', [
            'type' => 'SUPPORT', 'kind' => 'SUPPORT', 'job_type_code' => 'GP3', 'account_id' => 'acct_1',
        ])->assertCreated()->json('work_order_id');
    }

    public function test_reassign_changes_assignment_and_logs_history_without_status_change(): void
    {
        $id = $this->supportWo();
        $this->postJson("/api/work-orders/{$id}/assign", ['contractor_id' => 'con_1', 'team_id' => 'team_1'])->assertOk();

        $this->postJson("/api/work-orders/{$id}/reassign", ['contractor_id' => 'con_2', 'reason' => 'PATTERN_B'])
            ->assertOk()->assertJsonPath('status', 'ASSIGNED')->assertJsonPath('contractor_id', 'con_2');

        $this->assertDatabaseHas('wo_assignment_history', [
            'work_order_id' => $id, 'prev_contractor_id' => 'con_1', 'contractor_id' => 'con_2', 'reason' => 'PATTERN_B',
        ]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderReassigned']);
    }

    public function test_reassign_is_rejected_before_assignment(): void
    {
        $id = $this->supportWo(); // PENDING
        $this->postJson("/api/work-orders/{$id}/reassign", ['contractor_id' => 'con_2'])
            ->assertStatus(409)->assertJsonPath('errorCode', 'CONFLICT');
    }

    public function test_structured_note_validates_against_its_schema(): void
    {
        $id = $this->supportWo();

        // optical_readings requires oltPort + ontRxDbm.
        $this->postJson("/api/work-orders/{$id}/notes", ['note_kind' => 'optical_readings', 'payload' => ['oltPort' => '0/4/7']])
            ->assertStatus(422)->assertJsonPath('errorCode', 'NOTE_SCHEMA_INVALID');

        $this->postJson("/api/work-orders/{$id}/notes", ['note_kind' => 'optical_readings', 'payload' => ['oltPort' => '0/4/7', 'ontRxDbm' => -18.2]])
            ->assertCreated();
        $this->assertDatabaseHas('wo_note', ['work_order_id' => $id, 'note_kind' => 'optical_readings']);
    }

    public function test_two_step_finalize_enforces_the_checklist(): void
    {
        $id = $this->supportWo();
        $this->postJson("/api/work-orders/{$id}/assign", ['contractor_id' => 'con_1'])->assertOk();
        $this->postJson("/api/work-orders/{$id}/start")->assertOk();

        // First confirm parks in FINALIZATION_PENDING.
        $this->postJson("/api/work-orders/{$id}/finalize-first-confirm", ['final_reason' => 'RESOLVED_ON_SITE'])
            ->assertOk()->assertJsonPath('status', 'FINALIZATION_PENDING');

        // Second confirm fails — required notes (findings, solution, final_reason_set) are missing.
        $this->postJson("/api/work-orders/{$id}/finalize-second-confirm")
            ->assertStatus(422)->assertJsonPath('errorCode', 'FINALIZATION_CHECKLIST_FAILED');

        // Supply the required notes, then it completes.
        foreach (['findings' => [], 'solution' => [], 'final_reason_set' => ['finalReasonCode' => 'RESOLVED_ON_SITE']] as $kind => $payload) {
            $this->postJson("/api/work-orders/{$id}/notes", ['note_kind' => $kind, 'payload' => $payload, 'body' => 'ok'])->assertCreated();
        }

        $this->postJson("/api/work-orders/{$id}/finalize-second-confirm")
            ->assertOk()->assertJsonPath('status', 'COMPLETED');
        $this->assertSame('COMPLETED', WorkOrder::find($id)->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderFinalized']);
    }

    public function test_finalize_checklist_enforces_required_attachments(): void
    {
        // A checklist requiring a setup_photo attachment for this kind/job_type.
        WoFinalizationRequirement::query()->create([
            'id' => Id::make('wofr'), 'operator_code' => 'WIK', 'kind' => 'SUPPORT', 'job_type_code' => 'PHOTO_JOB',
            'required_note_kinds' => [], 'required_attachment_categories' => ['setup_photo'],
            'min_attachments_per_category' => ['setup_photo' => 1],
        ]);

        $id = $this->postJson('/api/work-orders', ['type' => 'SUPPORT', 'kind' => 'SUPPORT', 'job_type_code' => 'PHOTO_JOB'])
            ->assertCreated()->json('work_order_id');
        $this->postJson("/api/work-orders/{$id}/assign", ['contractor_id' => 'con_1'])->assertOk();
        $this->postJson("/api/work-orders/{$id}/start")->assertOk();
        $this->postJson("/api/work-orders/{$id}/finalize-first-confirm")->assertOk();

        // Missing the setup_photo -> checklist fails.
        $this->postJson("/api/work-orders/{$id}/finalize-second-confirm")
            ->assertStatus(422)->assertJsonPath('errorCode', 'FINALIZATION_CHECKLIST_FAILED');

        // Attach it, then it completes.
        $this->postJson("/api/work-orders/{$id}/attachments", ['category' => 'setup_photo', 'file_uri' => 's3://wo/photo.jpg'])
            ->assertCreated();
        $this->postJson("/api/work-orders/{$id}/finalize-second-confirm")->assertOk()->assertJsonPath('status', 'COMPLETED');
    }
}
