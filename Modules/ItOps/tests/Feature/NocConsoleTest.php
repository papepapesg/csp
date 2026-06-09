<?php

namespace Modules\ItOps\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * NOC console: the single-pane overview, service start/stop control, and the
 * correlation-id end-to-end trace across events/workflows/provisioning.
 */
class NocConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_overview_reports_platform_counters(): void
    {
        $this->getJson('/api/noc/overview')->assertOk()
            ->assertJsonStructure(['services', 'runningInstances', 'workflowIncidents', 'provisioningMismatches', 'outboxBacklog', 'slaOverdueTickets']);
    }

    public function test_service_stop_and_start_control(): void
    {
        $this->postJson('/api/noc/services/workflow-worker/stop')->assertOk()->assertJsonPath('status', 'DOWN');
        $this->assertDatabaseHas('service_control', ['service' => 'workflow-worker', 'command' => 'PAUSE']);
        $this->assertDatabaseHas('service_heartbeat', ['service' => 'workflow-worker', 'status' => 'DOWN']);

        $this->postJson('/api/noc/services/workflow-worker/start')->assertOk()->assertJsonPath('status', 'UP');
        $this->assertDatabaseHas('service_control', ['service' => 'workflow-worker', 'command' => 'RESUME']);
    }

    public function test_end_to_end_trace_reconstructs_a_subscription_journey(): void
    {
        // Run a real journey: create + activate a subscription, then trace by its id.
        $sub = $this->postJson('/api/subscriptions', [
            'customer_id' => 'c1', 'account_id' => 'a1', 'homepass_id' => 'h1', 'package_ref' => 'p1',
        ])->assertCreated()->json('subscription_id');
        $this->postJson("/api/subscriptions/{$sub}/activate", [], ['Idempotency-Key' => 'noc-act'])->assertStatus(202);
        Artisan::call('sophix:workflow:work', ['--once' => true]);

        $trace = $this->getJson('/api/noc/trace?key='.$sub)->assertOk()->json();
        $sources = collect($trace['timeline'])->pluck('source')->unique()->values()->all();

        // One key reconstructs the journey across subsystems.
        $this->assertContains('EVENT', $sources);
        $this->assertContains('WORKFLOW', $sources);
        $this->assertContains('TASK', $sources);
        $this->assertContains('PROVISIONING', $sources);
        $this->assertNotEmpty($trace['correlationIds']);
        // Time-ordered narrative.
        $times = collect($trace['timeline'])->pluck('at')->all();
        $sorted = $times; sort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_noc_requires_itops_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->getJson('/api/noc/overview')->assertForbidden();
    }
}
