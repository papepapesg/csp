<?php

namespace Modules\Provisioning\Tests\Feature;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Models\ProvisioningDesiredState;
use Modules\Provisioning\Models\ProvisioningReconciliationItem;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Provisioning\Services\ReconciliationService;
use Modules\Subscription\Models\Subscription;
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

        // EM-CFG-04 segregation of duties: the requester cannot approve their own force-sync.
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/approve")->assertStatus(403);

        // A different approver decides it; then it can execute -> item resolved.
        $approver = User::factory()->create(['operator_code' => 'WIK']);
        $approver->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($approver);
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/approve")
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertDatabaseHas('approval_request', ['request_id' => $fs['approval_request_id'], 'entity_type' => 'PROVISIONING_FORCE_SYNC', 'status' => 'APPROVED']);
        $this->postJson("/api/provisioning/force-sync-requests/{$fs['force_sync_id']}/execute")
            ->assertOk()->assertJsonPath('status', 'COMPLETED');

        $this->assertSame('RESOLVED', $item->refresh()->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ProvisioningForceSyncCompleted']);
    }

    /** R-ILM-S-3: a provisioning-affecting account status change reaches the network. */
    public function test_account_status_change_syncs_provisioning(): void
    {
        Context::setOperatorCode('WIK');
        // A subscription on the account, provisioned ACTIVE on the default NMS.
        Subscription::query()->create([
            'operator_code' => 'WIK', 'subscription_id' => 'SUB-AS', 'customer_id' => 'cust_as', 'account_id' => 'ACC-AS',
            'homepass_id' => 'hp_as', 'package_ref' => 'pkg_as', 'status_code' => 'ACTIVE', 'billing_mode' => 'POSTPAID',
        ]);
        ProvisioningDesiredState::query()->create([
            'desired_state_id' => Id::make('pds'), 'operator_code' => 'WIK',
            'subscription_id' => 'SUB-AS', 'target_code' => 'DEFAULT_NMS', 'subscriber_key' => 'SUB-AS', 'desired_status' => 'ACTIVE',
        ]);

        // ILM flags the account with a provisioning-affecting status change.
        app(EventBus::class)->publish(new DomainEvent(
            type: 'CustomerAccountStatusChanged',
            topic: 'sophix.customer.account-status-changed',
            payload: ['accountId' => 'ACC-AS', 'status' => 'INACTIVE', 'subStatus' => 'hold', 'affectsProvisioning' => true, 'customerVisible' => true],
            aggregateType: 'CustomerAccount', aggregateId: 'ACC-AS',
        ));
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();

        // Provisioning applied the suspension to the account's service.
        $cmd = ProvisioningCommand::query()
            ->where('subscription_id', 'SUB-AS')->where('action', 'ACCOUNT_STATUS_SYNC')->first();
        $this->assertNotNull($cmd);
        $this->assertSame('SUSPENDED', $cmd->desired_state['desiredStatus']);
        $this->assertSame('DEFAULT_NMS', $cmd->target_code);
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
