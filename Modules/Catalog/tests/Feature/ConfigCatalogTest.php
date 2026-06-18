<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConfigCatalogTest extends TestCase
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

    public function test_config_catalogs_crud(): void
    {
        $this->postJson('/api/config/wallet-types', ['code' => 'MAIN', 'name' => 'Main wallet', 'currency' => 'KES'])->assertCreated();
        $this->postJson('/api/config/adjustment-types', ['code' => 'GOODWILL', 'name' => 'Goodwill credit', 'direction' => 'CREDIT'])->assertCreated();
        $this->postJson('/api/config/voice-tariffs', ['code' => 'ONNET_STD', 'name' => 'On-net', 'destination' => 'ONNET', 'rate_per_min' => 2.5])->assertCreated();
        // equipment models live in OSR (equipment_sku), not this config registry.

        $this->getJson('/api/config/voice-tariffs')->assertOk()->assertJsonPath('items.0.code', 'ONNET_STD');
        $this->postJson('/api/config/unknown', ['code' => 'X', 'name' => 'Y'])->assertNotFound();
    }
}
