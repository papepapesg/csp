<?php

namespace Tests\Feature;

use App\Foundation\Portals\PortalRegistry;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * FE-APP-01 multi-app portals: subdomain → app resolution, the launcher app grid filtered by
 * permission, and per-app sidebar nav (each subdomain shows only its own app).
 */
class PortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portals.base_domain' => 'sophix.test']);
    }

    public function test_subdomain_resolves_to_its_app(): void
    {
        $this->assertSame('crm', PortalRegistry::slugForHost('crm.sophix.test'));
        $this->assertSame('catalog', PortalRegistry::slugForHost('catalog.sophix.test'));
        $this->assertNull(PortalRegistry::slugForHost('sophix.test'));          // bare base = launcher
        $this->assertNull(PortalRegistry::slugForHost('app.sophix.test'));      // explicit launcher slug
        $this->assertSame('crm', PortalRegistry::currentForHost('crm.sophix.test')['slug']);
    }

    public function test_launcher_shows_only_apps_the_user_may_open(): void
    {
        $this->seed(RbacSeeder::class);
        // A user who can only read the catalog should see Catalog (+ permless apps) but NOT Settings.
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->givePermissionTo('catalog.read');

        $this->actingAs($user)->get('http://app.sophix.test/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Launcher'));

        $tiles = collect(PortalRegistry::accessibleTiles($user));
        $this->assertTrue($tiles->contains(fn ($t) => $t['slug'] === 'catalog'));
        $this->assertTrue($tiles->contains(fn ($t) => $t['slug'] === 'ops'));      // perm = null, open to all
        $this->assertFalse($tiles->contains(fn ($t) => $t['slug'] === 'settings')); // needs rbac.manage
    }

    public function test_app_subdomain_root_redirects_into_that_app(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');

        // crm.<base>/ → its first screen (customers), staying on the subdomain (relative).
        $this->actingAs($user)->get('http://crm.sophix.test/')
            ->assertRedirect('/customers');
    }

    public function test_super_admin_sees_every_app(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');

        $this->assertCount(count(config('portals.apps')), PortalRegistry::accessibleTiles($user));
    }
}
