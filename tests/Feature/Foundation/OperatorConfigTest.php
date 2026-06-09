<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use Database\Seeders\OperatorConfigSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Operator deployment config: identity/locale/currency/theme read by every view. */
class OperatorConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_config_read_and_admin_update(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(OperatorConfigSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        // Every authed frontend reads its operator's settings.
        $this->getJson('/api/operator-config')->assertOk()
            ->assertJsonPath('display_name', 'Wananchi Kenya')
            ->assertJsonPath('currency_code', 'KES')
            ->assertJsonPath('default_locale', 'en');

        // Admin re-brands at runtime: theme + language + currency are data.
        $this->patchJson('/api/operator-config', ['theme_primary_color' => '#16a34a', 'default_locale' => 'sw'])
            ->assertOk()->assertJsonPath('theme_primary_color', '#16a34a');
        $this->getJson('/api/operator-config')->assertOk()->assertJsonPath('default_locale', 'sw');
    }

    public function test_requests_run_in_the_operators_locale(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(OperatorConfigSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WTZ']); // Swahili deployment
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        // Backend locale follows operator config (Content-Language) …
        $this->getJson('/api/operator-config')->assertOk()->assertHeader('Content-Language', 'sw');
        // … with a per-request user override (a French-speaking agent).
        $this->getJson('/api/operator-config', ['X-Locale' => 'fr'])->assertOk()->assertHeader('Content-Language', 'fr');
    }

    public function test_update_requires_itops_manage(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->patchJson('/api/operator-config', ['display_name' => 'X'])->assertForbidden();
    }
}
