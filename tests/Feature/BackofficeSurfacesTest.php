<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * FE-APP-01 backoffice shell: the dashboard summary read model and that every operational
 * surface route renders its Inertia page (route/UI proof for matrix #47).
 */
class BackofficeSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->seed(RbacSeeder::class);
        $u = User::factory()->create(['operator_code' => 'WIK']);
        $u->assignRole('SUPER_ADMIN');

        return $u;
    }

    public function test_dashboard_summary_returns_resilient_widgets(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonStructure([
                'widgets' => ['myTasks' => ['available'], 'kycPending', 'dunningRisk', 'woBacklog', 'activationFailures', 'stockExceptions'],
                'onboarding', 'recent',
            ])
            ->assertJsonPath('widgets.kycPending.available', true);
    }

    public function test_browser_session_authenticates_api_calls(): void
    {
        // Browser-faithful: a REAL login (session cookie), then an /api/* fetch — the exact
        // path the SPA uses. Sanctum::actingAs() bypasses this, which is how a missing
        // statefulApi() shipped: the UI 401'd in production while every test stayed green.
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK', 'password' => bcrypt('password')]);
        $user->assignRole('SUPER_ADMIN');

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->getJson('/api/dashboard/summary')->assertOk()
            ->assertJsonStructure(['widgets']);

        // And without any session, the API stays closed (flush the test client's cached
        // guard state — within one test the auth manager memoizes the resolved user).
        $this->post('/logout');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson('/api/dashboard/summary')->assertStatus(401);
    }

    public function test_global_search_federates_across_entities_with_isolation(): void
    {
        Sanctum::actingAs($this->user());
        \Modules\Ilm\Models\Customer::query()->create([
            'customer_id' => \App\Foundation\Support\Id::make('cust'), 'operator_code' => 'WIK',
            'type' => 'RES', 'name' => 'Ada Lovelace', 'primary_msisdn' => '+254712345678',
        ]);

        $res = $this->getJson('/api/search?q=Ada')->assertOk();
        $groups = collect($res->json('groups'));
        $this->assertTrue($groups->contains(fn ($g) => $g['type'] === 'customer'));
        $customer = $groups->firstWhere('type', 'customer');
        $this->assertSame('Ada Lovelace', $customer['items'][0]['title']);

        // Sub-2-char queries return nothing (no expensive fan-out).
        $this->getJson('/api/search?q=A')->assertOk()->assertJsonPath('groups', []);
    }

    public function test_each_backoffice_surface_renders_its_page(): void
    {
        $user = $this->user();

        $routes = [
            '/dashboard' => 'Dashboard',
            '/subscriptions' => 'Subscriptions/Index',
            '/billing' => 'Billing/Console',
            '/fulfillment' => 'Fulfillment/Console',
            '/work-orders' => 'WorkOrders/Console',
            '/workforce' => 'Workforce/Console',
            '/equipment' => 'Osr/Equipment',
            '/admin' => 'Admin/Console',
            '/catalog/setup' => 'Catalog/Setup',
            '/reports' => 'Reports/Dashboard',
            '/warehouse' => 'Osr/Warehouse',
        ];

        foreach ($routes as $url => $component) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
        }
    }
}
