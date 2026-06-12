<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\BackofficeDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Models\Customer;
use Tests\TestCase;

/**
 * The backoffice demo dataset seeds cleanly and lights up the operational surfaces — a fresh demo
 * opens with customers, in-flight onboarding orders and tickets to explore.
 */
class BackofficeDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seed_populates_the_backoffice(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(BackofficeDemoSeeder::class);

        // Several demo customers exist (the spread the surfaces explore).
        $this->assertGreaterThanOrEqual(4, Customer::query()->count());

        // The dashboard summary reflects the seeded data without erroring.
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/dashboard/summary')->assertOk();
        $this->assertTrue($res->json('widgets.kycPending.available'));
        // At least one fulfillment order is in flight (the onboarding funnel has data).
        $onboarding = $res->json('onboarding');
        $this->assertNotEmpty($onboarding['data'] ?? $onboarding);

        // Federated search finds a seeded customer.
        $this->getJson('/api/search?q=Amani')->assertOk()
            ->assertJsonPath('groups.0.type', 'customer');
    }
}
