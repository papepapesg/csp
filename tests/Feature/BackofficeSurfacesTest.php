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
        ];

        foreach ($routes as $url => $component) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->component($component));
        }
    }
}
