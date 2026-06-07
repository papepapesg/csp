<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\WorkOrder\Database\Seeders\WoSupportSeeder;
use Modules\WorkOrder\Models\WorkOrder;
use Tests\TestCase;

class WorkOrderShiftingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WoSupportSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    public function test_shifting_flow_runs_both_phases(): void
    {
        $id = $this->postJson('/api/work-orders', [
            'type' => 'SHIFTING', 'kind' => 'SHIFTING', 'job_type_code' => 'SHIFT', 'customer_id' => 'c1',
        ], ['Idempotency-Key' => 'wo-shift'])->assertCreated()->json('work_order_id');

        $this->postJson("/api/work-orders/{$id}/shifting-flow")->assertStatus(202);
        $this->drain(); // mark DISCONNECT -> await disconnect (user task)
        $this->assertSame('DISCONNECT', WorkOrder::find($id)->current_phase);

        // Tech disconnects at source.
        $this->postJson("/api/work-orders/{$id}/advance-phase")->assertStatus(202);
        $this->drain(); // mark RECONNECT -> await reconnect
        $this->assertSame('RECONNECT', WorkOrder::find($id)->current_phase);

        // Tech reconnects at target.
        $this->postJson("/api/work-orders/{$id}/advance-phase")->assertStatus(202);
        $this->drain(); // finalize

        $wo = WorkOrder::find($id);
        $this->assertSame('FINALIZED', $wo->status);
        $this->assertSame('RECONNECTED', $wo->final_reason);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderShiftingCompleted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderPhaseTransitioned']);
    }
}
