<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageVersion;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

class SubscriptionUpgradeTest extends TestCase
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

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    /** Create an ACTIVE package with a priced current version; returns [packageId, versionId]. */
    private function package(string $code, float $price): array
    {
        $pkgId = Id::make('pkg');
        $verId = Id::make('pkv');
        $pkg = Package::query()->create([
            'id' => $pkgId, 'operator_code' => 'WIK', 'code' => $code, 'name' => $code,
            'status' => 'ACTIVE', 'current_version_id' => $verId,
        ]);
        PackageVersion::query()->create([
            'id' => $verId, 'package_id' => $pkgId, 'price' => $price, 'currency' => 'KES',
            'effective_from' => now(), 'status' => 'ACTIVE',
        ]);

        return [$pkgId, $verId];
    }

    private function activeSubscription(string $pkgId, string $verId): string
    {
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => $pkgId,
            'package_version_id' => $verId, 'status_code' => 'ACTIVE', 'currency' => 'KES',
        ]);

        return $sub->subscription_id;
    }

    public function test_upgrade_changes_package_and_records_previous(): void
    {
        [$src] = $this->package('PKG_BASIC', 1000);
        [$srcPkg, $srcVer] = [$src, Package::find($src)->current_version_id];
        [$tgt, $tgtVer] = $this->package('PKG_PREMIUM', 2500);
        $id = $this->activeSubscription($srcPkg, $srcVer);

        $this->postJson("/api/subscriptions/{$id}/upgrade", ['targetPackageRef' => $tgt], ['Idempotency-Key' => 'up-1'])
            ->assertStatus(202);
        $this->drain();

        $sub = Subscription::find($id);
        $this->assertSame($tgt, $sub->package_ref);
        $this->assertSame($srcPkg, $sub->previous_package_ref);
        $this->assertNull($sub->current_transition_type); // transient marker cleared on commit
        $this->assertSame('ACTIVE', $sub->status_code);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionUpgraded']);
    }

    public function test_upgrade_to_cheaper_package_is_rejected(): void
    {
        [$srcPkg] = $this->package('PKG_PREMIUM2', 2500);
        $srcVer = Package::find($srcPkg)->current_version_id;
        [$tgt] = $this->package('PKG_BASIC2', 1000); // cheaper -> not an upgrade
        $id = $this->activeSubscription($srcPkg, $srcVer);

        $this->postJson("/api/subscriptions/{$id}/upgrade", ['targetPackageRef' => $tgt], ['Idempotency-Key' => 'up-2'])
            ->assertStatus(202);
        $this->drain();

        // Gateway rejected -> package unchanged.
        $this->assertSame($srcPkg, Subscription::find($id)->package_ref);
    }

    public function test_downgrade_changes_package(): void
    {
        [$srcPkg] = $this->package('PKG_PREMIUM3', 2500);
        $srcVer = Package::find($srcPkg)->current_version_id;
        [$tgt] = $this->package('PKG_BASIC3', 1000);
        $id = $this->activeSubscription($srcPkg, $srcVer);

        $this->postJson("/api/subscriptions/{$id}/downgrade", ['targetPackageRef' => $tgt], ['Idempotency-Key' => 'dn-1'])
            ->assertStatus(202);
        $this->drain();

        $sub = Subscription::find($id);
        $this->assertSame($tgt, $sub->package_ref);
        $this->assertNull($sub->current_transition_type); // transient marker cleared on commit
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionDowngraded']);
    }
}
