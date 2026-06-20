<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Rbac\Services\RbacScopeService;
use Tests\TestCase;

class WorkOrderApiTest extends TestCase
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

    private function create(): string
    {
        return $this->postJson('/api/work-orders', [
            'type' => 'INSTALLATION',
            'account_id' => 'acct_1',
            'tech_region_id' => 'KE-NRB-KAREN',
            'source_type' => 'FULFILLMENT',
        ])->assertCreated()->assertJsonPath('status', 'PENDING')->json('work_order_id');
    }

    public function test_full_install_lifecycle(): void
    {
        $id = $this->create();
        $this->assertStringStartsWith('wo_', $id);

        $this->postJson("/api/work-orders/{$id}/assign", ['contractor_id' => 'con_1', 'team_id' => 'team_1'])
            ->assertOk()->assertJsonPath('status', 'ASSIGNED');
        $this->postJson("/api/work-orders/{$id}/start")->assertOk()->assertJsonPath('status', 'IN_PROGRESS');
        $this->postJson("/api/work-orders/{$id}/finalize", ['resolution_code' => 'INSTALL_OK', 'findings' => ['ont' => 'SN123']])
            ->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WorkOrderFinalized']);
        $this->assertDatabaseHas('wo_status_history', ['work_order_id' => $id, 'new_status' => 'COMPLETED']);
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $id = $this->create();
        // cannot finalize a PENDING (must assign+start first)
        $this->postJson("/api/work-orders/{$id}/finalize")
            ->assertStatus(409)
            ->assertJsonPath('errorCode', 'CONFLICT');
    }

    public function test_create_requires_dispatch_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->postJson('/api/work-orders', ['type' => 'SUPPORT'])->assertForbidden();
    }

    public function test_tech_region_scope_gates_work_order_creation(): void
    {
        // EM-CFG-03 §8.5: a region-scoped dispatcher may only create WOs in their region.
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('DISPATCHER'); // has workorder.assign, not SUPER_ADMIN
        app(RbacScopeService::class)->assign($user->uid, [
            'scopeType' => 'TECH_REGION', 'scopeValue' => 'KE-NRB-KAREN', 'operatorCode' => 'WIK',
        ]);
        Sanctum::actingAs($user);

        // Within scope -> allowed.
        $this->postJson('/api/work-orders', [
            'type' => 'INSTALLATION', 'account_id' => 'a1', 'tech_region_id' => 'KE-NRB-KAREN', 'source_type' => 'FULFILLMENT',
        ])->assertCreated();

        // Outside scope -> 403 OUT_OF_SCOPE.
        $this->postJson('/api/work-orders', [
            'type' => 'INSTALLATION', 'account_id' => 'a1', 'tech_region_id' => 'KE-MSA-NYALI', 'source_type' => 'FULFILLMENT',
        ])->assertStatus(403)->assertJsonPath('errorCode', 'OUT_OF_SCOPE');
    }
}
