<?php

namespace Modules\ItOps\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Modules\ItOps\Support\Heartbeat;
use Tests\TestCase;

class ItOpsTest extends TestCase
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

    public function test_logs_are_captured_and_searchable(): void
    {
        Log::channel('database')->warning('Provisioning rejected by NMS', ['target' => 'GPON']);
        Log::channel('database')->info('Routine heartbeat');

        $this->getJson('/api/itops/logs?level=warning&q=NMS')
            ->assertOk()
            ->assertJsonPath('totalElements', 1)
            ->assertJsonPath('items.0.message', 'Provisioning rejected by NMS');
    }

    public function test_service_heartbeats_and_status(): void
    {
        Heartbeat::ping('workflow-worker', 'w-1', ['lastBatch' => 3]);

        $this->getJson('/api/itops/services')
            ->assertOk()
            ->assertJsonPath('services.0.service', 'workflow-worker')
            ->assertJsonPath('services.0.status', 'UP')
            ->assertJsonStructure(['queues' => ['workflowTasksCreated', 'outboxUnpublished']]);
    }

    public function test_restart_request_is_honoured_by_worker_control(): void
    {
        Heartbeat::ping('workflow-worker', 'w-1');

        $this->postJson('/api/itops/services/workflow-worker/restart')->assertStatus(202);

        // The worker's control check returns true once, then clears.
        $this->assertTrue(Heartbeat::shouldStop('workflow-worker'));
        $this->assertFalse(Heartbeat::shouldStop('workflow-worker'));
    }

    public function test_requires_itops_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->getJson('/api/itops/services')->assertForbidden();
    }
}
