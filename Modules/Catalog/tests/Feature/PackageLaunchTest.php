<?php

namespace Modules\Catalog\Tests\Feature;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\PackageLaunchPlan;
use Modules\Catalog\Plm\Models\PackageService;
use Modules\Catalog\Plm\Models\PackageVersion;
use Modules\Catalog\Plm\Models\Service;
use Modules\Catalog\Plm\Models\ServiceClass;
use Tests\TestCase;

/**
 * SIP-02 Package Launch Lifecycle (DD §14): validation gating on inactive services,
 * the auto-approve and EM-CFG-04 approval-callback paths, scheduled activation via the
 * worker, the sellable read model filtered by channel/franchise, suspension hiding a
 * package, end-of-sale blocking new sales, and the version cutover updating
 * current_version_id for new sales only.
 */
class PackageLaunchTest extends TestCase
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

    /**
     * Seed a SIP-01 package + one version + one composed service.
     *
     * @return array{0:Package,1:PackageVersion,2:Service}
     */
    private function seedPackage(string $serviceStatus = 'ACTIVE', array $packageOverrides = [], array $versionOverrides = []): array
    {
        $sc = ServiceClass::query()->create(['id' => Id::make('scls'), 'operator_code' => 'WIK', 'name' => 'Internet']);
        $service = Service::query()->create([
            'id' => Id::make('svc'), 'operator_code' => 'WIK', 'name' => 'Fiber 100M', 'code' => 'NET100_'.Id::make('c'),
            'service_class_id' => $sc->id, 'status' => $serviceStatus,
        ]);
        $package = Package::query()->create(array_merge([
            'id' => Id::make('pkg'), 'operator_code' => 'WIK', 'code' => 'FIBER_100M_'.strtoupper(substr(Id::make(''), 1, 6)),
            'name' => 'Fiber 100M Residential', 'display_name' => 'Fiber 100M Residential',
            'status' => Package::STATUS_DRAFT, 'billing_frequency_days' => 30,
        ], $packageOverrides));
        PackageService::query()->create(['id' => Id::make('pks'), 'package_id' => $package->id, 'service_id' => $service->id]);
        $version = PackageVersion::query()->create(array_merge([
            'id' => Id::make('pkv'), 'package_id' => $package->id, 'price' => 3000.00, 'currency' => 'KES',
            'effective_from' => now()->subDay(), 'status' => PackageVersion::STATUS_PENDING,
        ], $versionOverrides));

        return [$package, $version, $service];
    }

    /** @return array{0:string,1:Package,2:PackageVersion} launchPlanId + package + version */
    private function createPlan(array $availability = [['channelCode' => 'SALES_APP', 'franchiseId' => 'fr-019', 'techRegionCode' => 'NRB-WEST']], ?string $launchAt = null, string $serviceStatus = 'ACTIVE'): array
    {
        [$package, $version] = $this->seedPackage($serviceStatus);
        $id = $this->postJson('/api/package-launch-plans', [
            'operatorCode' => 'WIK', 'packageId' => $package->id, 'packageCode' => $package->code,
            'packageVersionId' => $version->id, 'launchType' => 'FIRST_LAUNCH',
            'requestedLaunchAt' => $launchAt, 'availability' => $availability,
        ], ['Idempotency-Key' => 'plp-'.Id::make('k')])
            ->assertCreated()->assertJsonPath('status', 'DRAFT')->json('launch_plan_id');

        return [$id, $package, $version];
    }

    public function test_validation_fails_on_inactive_service(): void
    {
        [$id] = $this->createPlan(serviceStatus: 'RETIRED');

        $res = $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $res->assertJsonPath('status', 'DRAFT'); // stays DRAFT — a FAIL blocks review
        $this->assertDatabaseHas('package_launch_check', ['launch_plan_id' => $id, 'check_code' => 'SERVICE_ACTIVE', 'check_status' => 'FAIL']);

        // Cannot submit a plan that is not READY_FOR_REVIEW.
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertStatus(409);
    }

    public function test_auto_approve_path_activates(): void
    {
        // No ApprovalDefinition → EM-CFG-04 auto-approves; launch now (no future date) → APPROVED, then activate.
        [$id, $package, $version] = $this->createPlan();

        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk()->assertJsonPath('status', 'READY_FOR_REVIEW');
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageLaunchApproved']);

        $this->postJson("/api/package-launch-plans/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertDatabaseHas('package', ['id' => $package->id, 'status' => 'ACTIVE', 'current_version_id' => $version->id]);
        $this->assertDatabaseHas('package_version', ['id' => $version->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('package_availability', ['package_id' => $package->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('package_version_cutover', ['package_id' => $package->id, 'to_version_id' => $version->id, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('package_lifecycle_event', ['package_id' => $package->id, 'event_type' => 'PACKAGE_ACTIVATED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageActivated']);
    }

    public function test_approval_definition_routes_through_em_cfg_04_then_callback_activates(): void
    {
        ApprovalDefinition::defineChain('WIK', 'PACKAGE_LAUNCH_PLAN', 'PACKAGE_LAUNCH_APPROVAL', [
            ['approver_kind' => 'ROLE', 'approver_roles' => ['SUPER_ADMIN']],
        ]);

        [$id, $package, $version] = $this->createPlan();
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk()->assertJsonPath('status', 'READY_FOR_REVIEW');

        // A definition with a null threshold → approval required → PENDING_APPROVAL.
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk()->assertJsonPath('status', 'PENDING_APPROVAL');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageLaunchApprovalRequired']);
        $this->assertSame('PENDING_APPROVAL', PackageLaunchPlan::find($id)->status);

        // The EM-CFG-04 callback approves → APPROVED → activate.
        $this->postJson("/api/package-launch-plans/{$id}/approval-outcome", ['outcome' => 'APPROVED'])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
        $this->postJson("/api/package-launch-plans/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertDatabaseHas('package', ['id' => $package->id, 'current_version_id' => $version->id, 'status' => 'ACTIVE']);
    }

    public function test_em_cfg_04_rejection_blocks_launch(): void
    {
        ApprovalDefinition::defineChain('WIK', 'PACKAGE_LAUNCH_PLAN', 'PACKAGE_LAUNCH_APPROVAL', [
            ['approver_kind' => 'ROLE', 'approver_roles' => ['SUPER_ADMIN']],
        ]);
        [$id, $package] = $this->createPlan();
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk()->assertJsonPath('status', 'PENDING_APPROVAL');

        $this->postJson("/api/package-launch-plans/{$id}/approval-outcome", ['outcome' => 'REJECTED'])
            ->assertOk()->assertJsonPath('status', 'REJECTED');
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageLaunchRejected']);
        // Package remains not sellable.
        $this->assertDatabaseMissing('package', ['id' => $package->id, 'status' => 'ACTIVE']);
    }

    public function test_scheduled_activation_via_run_due(): void
    {
        // Future launch date → SCHEDULED on approval; run-due before the date does nothing.
        [$id, $package, $version] = $this->createPlan(launchAt: now()->addMinutes(10)->toAtomString());
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk()->assertJsonPath('status', 'SCHEDULED');

        $this->postJson('/api/package-launch-plans/run-due', ['operatorCode' => 'WIK'])->assertOk()->assertJsonPath('activated', 0);
        $this->assertSame('SCHEDULED', PackageLaunchPlan::find($id)->status);

        // Move the due time into the past, then the worker activates it.
        PackageLaunchPlan::where('launch_plan_id', $id)->update(['requested_launch_at' => now()->subMinute()]);
        $this->postJson('/api/package-launch-plans/run-due', ['operatorCode' => 'WIK'])->assertOk()->assertJsonPath('activated', 1);
        $this->assertSame('ACTIVE', PackageLaunchPlan::find($id)->status);
        $this->assertDatabaseHas('package_version', ['id' => $version->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('package', ['id' => $package->id, 'status' => 'ACTIVE', 'current_version_id' => $version->id]);
    }

    public function test_available_packages_filtered_by_channel_and_franchise(): void
    {
        [$id, $package, $version] = $this->createPlan([
            ['channelCode' => 'SALES_APP', 'franchiseId' => 'fr-019', 'techRegionCode' => 'NRB-WEST'],
            ['channelCode' => 'BACKOFFICE', 'franchiseId' => 'fr-019', 'techRegionCode' => 'NRB-WEST'],
        ]);
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // Matching channel + franchise → returned with the §6.6 shape.
        $res = $this->getJson('/api/packages/available?operatorCode=WIK&channelCode=SALES_APP&franchiseId=fr-019&techRegionCode=NRB-WEST')->assertOk();
        $res->assertJsonPath('packages.0.packageId', $package->id)
            ->assertJsonPath('packages.0.packageVersionId', $version->id)
            ->assertJsonPath('packages.0.price', 3000)
            ->assertJsonPath('packages.0.currency', 'KES')
            ->assertJsonPath('packages.0.billingFrequencyDays', 30);
        $this->assertContains('SALES_APP', $res->json('packages.0.availableChannels'));

        // A different franchise sees nothing for the scoped rows.
        $this->getJson('/api/packages/available?operatorCode=WIK&channelCode=SALES_APP&franchiseId=fr-999')
            ->assertOk()->assertJsonCount(0, 'packages');
    }

    public function test_suspend_hides_package_from_available(): void
    {
        [$id, $package] = $this->createPlan();
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/activate")->assertOk();

        $this->getJson('/api/packages/available?operatorCode=WIK&channelCode=SALES_APP')->assertOk()->assertJsonCount(1, 'packages');

        $this->postJson("/api/packages/{$package->id}/availability/suspend", [
            'channelCode' => 'SALES_APP', 'reasonCode' => 'TEMPORARY_PROVISIONING_ISSUE',
        ])->assertOk()->assertJsonPath('suspended', 1);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageAvailabilityChanged']);
        // R-SIP-02-11: master status is unchanged.
        $this->assertDatabaseHas('package', ['id' => $package->id, 'status' => 'ACTIVE']);

        // The outbox dispatch fires the lifecycle event → CatalogCacheInvalidator evicts the snapshot.
        Artisan::call('sophix:outbox:dispatch');
        $this->getJson('/api/packages/available?operatorCode=WIK&channelCode=SALES_APP')->assertOk()->assertJsonCount(0, 'packages');
    }

    public function test_end_of_sale_blocks_new_sales(): void
    {
        [$id, $package] = $this->createPlan();
        $this->postJson("/api/package-launch-plans/{$id}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/submit-review", [])->assertOk();
        $this->postJson("/api/package-launch-plans/{$id}/activate")->assertOk();

        $this->postJson('/api/package-retirement-plans', [
            'operatorCode' => 'WIK', 'packageId' => $package->id, 'retirementType' => 'END_OF_SALE',
            'existingSubscriberPolicy' => 'KEEP_AS_IS', 'reasonCode' => 'PRODUCT_REPLACED',
        ], ['Idempotency-Key' => 'prp-1'])->assertCreated()->assertJsonPath('status', 'SCHEDULED');

        $this->assertDatabaseHas('package', ['id' => $package->id, 'status' => 'END_OF_SALE']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageEndOfSale']);
        // New sales blocked: no longer in the sellable read model.
        $this->getJson('/api/packages/available?operatorCode=WIK&channelCode=SALES_APP')->assertOk()->assertJsonCount(0, 'packages');
    }

    public function test_migrate_required_retirement_needs_workflow_ref(): void
    {
        [, $package] = $this->seedPackage(packageOverrides: ['status' => Package::STATUS_ACTIVE]);

        $this->postJson('/api/package-retirement-plans', [
            'operatorCode' => 'WIK', 'packageId' => $package->id, 'retirementType' => 'END_OF_SALE',
            'existingSubscriberPolicy' => 'MIGRATE_REQUIRED', // missing migrationWorkflowRef → R-SIP-02-10
        ], ['Idempotency-Key' => 'prp-2'])->assertStatus(422)->assertJsonPath('errorCode', 'MIGRATION_WORKFLOW_REQUIRED');
    }

    public function test_version_cutover_updates_current_version_for_new_sales_only(): void
    {
        // First launch v1 active.
        [$id1, $package, $v1] = $this->createPlan();
        $this->postJson("/api/package-launch-plans/{$id1}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id1}/submit-review", [])->assertOk();
        $this->postJson("/api/package-launch-plans/{$id1}/activate")->assertOk();
        $this->assertDatabaseHas('package', ['id' => $package->id, 'current_version_id' => $v1->id]);

        // Add a second version and launch it as a VERSION_CUTOVER.
        $v2 = PackageVersion::query()->create([
            'id' => Id::make('pkv'), 'package_id' => $package->id, 'price' => 3500.00, 'currency' => 'KES',
            'effective_from' => now(), 'status' => PackageVersion::STATUS_PENDING,
        ]);
        $id2 = $this->postJson('/api/package-launch-plans', [
            'operatorCode' => 'WIK', 'packageId' => $package->id, 'packageCode' => $package->code,
            'packageVersionId' => $v2->id, 'launchType' => 'VERSION_CUTOVER',
            'availability' => [['channelCode' => 'SALES_APP', 'franchiseId' => 'fr-019', 'techRegionCode' => 'NRB-WEST']],
        ], ['Idempotency-Key' => 'plp-cut'])->assertCreated()->json('launch_plan_id');
        $this->postJson("/api/package-launch-plans/{$id2}/validate")->assertOk();
        $this->postJson("/api/package-launch-plans/{$id2}/submit-review", [])->assertOk();
        $this->postJson("/api/package-launch-plans/{$id2}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        // current_version_id moves to v2 (new sales); v1 is SUPERSEDED, not deleted (existing subs keep it).
        $this->assertDatabaseHas('package', ['id' => $package->id, 'current_version_id' => $v2->id]);
        $this->assertDatabaseHas('package_version', ['id' => $v1->id, 'status' => 'SUPERSEDED']);
        $this->assertDatabaseHas('package_version', ['id' => $v2->id, 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('package_version_cutover', ['package_id' => $package->id, 'from_version_id' => $v1->id, 'to_version_id' => $v2->id, 'status' => 'COMPLETED']);
    }
}
