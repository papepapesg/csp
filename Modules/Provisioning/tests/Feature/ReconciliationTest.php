<?php

namespace Modules\Provisioning\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Provisioning\Services\ReconciliationService;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProvisioningTargetSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    /** A confirmed command records desired state; a clean network reconciles with no mismatch. */
    public function test_clean_reconciliation_has_no_mismatch(): void
    {
        app(ProvisioningService::class)->broadcast('SUB-1', 'ACTIVATE', [[
            'target_code' => 'DEFAULT_NMS', 'service_ref' => 'svc_inet', 'desired_state' => ['desiredStatus' => 'ACTIVE'],
        ]]);
        $this->assertDatabaseHas('provisioning_desired_state', ['subscription_id' => 'SUB-1', 'desired_status' => 'ACTIVE']);

        $run = app(ReconciliationService::class)->run('DEFAULT_NMS');
        $this->assertSame(1, $run->desired_count);
        $this->assertSame(0, $run->mismatch_count);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ProvisioningReconciliationRunCompleted']);
    }

    /** Simulated network drift opens a review item; force-sync resolves it. */
    public function test_drift_opens_item_and_force_sync_resolves(): void
    {
        // Desired ACTIVE but the stub network is told to report SUSPENDED.
        app(ProvisioningService::class)->broadcast('SUB-2', 'ACTIVATE', [[
            'target_code' => 'DEFAULT_NMS', 'service_ref' => 'svc_inet',
            'desired_state' => ['desiredStatus' => 'ACTIVE', 'simulateObservedStatus' => 'SUSPENDED'],
        ]]);

        $run = $this->postJson('/api/provisioning/reconciliation/run', ['targetCode' => 'DEFAULT_NMS'])
            ->assertOk()->json();
        $this->assertSame(1, $run['mismatch_count']);

        $item = ProvisioningReconciliationItem::query()->where('subscription_id', 'SUB-2')->firstOrFail();
        $this->assertSame('OPEN', $item->status);
        $this->assertSame('SUSPENDED', $item->observed_status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ProvisioningReconciliationItemOpened']);

        // Force-sync is approval-gated (R-PROV-07): raising it creates a PENDING_APPROVAL
        // request and moves the item to IN_REVIEW — the network is NOT touched yet.
        $fs = $this->postJson("/api/provisioning/reconciliation/items/{$item->item_id}/force-sync", ['reason' => 'wrong profile'])
            ->assertCreated()->assertJsonPath('status', 'PENDING_APPROVAL')->json();
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ProvisioningForceSyncRequested']);
        $this->assertSame('IN_REVIEW', $item->refresh()->status);

        // Cannot execute before approval.
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/execute")
            ->assertStatus(409);

        // Approve, then execute -> item resolved.
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/approve")
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/execute")
            ->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->assertSame('RESOLVED', $item->refresh()->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ProvisioningForceSyncCompleted']);
    }

    /** The polling worker reconciles all active targets. */
    public function test_reconcile_worker_runs(): void
    {
        app(ProvisioningService::class)->broadcast('SUB-3', 'ACTIVATE', [[
            'target_code' => 'DEFAULT_NMS', 'desired_state' => ['desiredStatus' => 'ACTIVE'],
        ]]);

        $this->artisan('sophix:provisioning:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('provisioning_reconciliation_run', ['target_code' => 'DEFAULT_NMS', 'status' => 'COMPLETED']);
    }
}
