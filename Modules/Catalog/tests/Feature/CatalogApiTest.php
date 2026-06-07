<?php

namespace Modules\Catalog\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(CatalogPolicySeeder::class); // rules.service-catalog / rules.homepass-catalog
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('CATALOG_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_service_class_then_service_creation(): void
    {
        $class = $this->postJson('/api/service-classes', ['name' => 'Internet'])->assertCreated()->json('id');

        $this->postJson('/api/services', [
            'name' => 'Internet 100Mbps',
            'code' => 'INT-100',
            'service_class_id' => $class,
            'consumption_model' => 'FLAT',
        ])->assertCreated()
            ->assertJsonPath('service.code', 'INT-100')
            ->assertJsonPath('policyWarnings', []); // no rule fired

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'ServiceCreated']);
    }

    public function test_catalog_config_rule_flags_addressable_service_without_equipment(): void
    {
        $class = $this->postJson('/api/service-classes', ['name' => 'Internet'])->json('id');

        // rules.service-catalog R-PLM-SVC-001 fires (addressable + no equipment ref).
        $this->postJson('/api/services', [
            'name' => 'Fibre addressable', 'code' => 'FIB-ADDR', 'service_class_id' => $class,
            'is_addressable' => true,
        ])->assertCreated()
            ->assertJsonPath('policyWarnings.0.ruleId', 'R-PLM-SVC-001')
            ->assertJsonPath('policyWarnings.0.field', 'equipment_requirement_ref');
    }

    public function test_homepass_config_rule_flags_unsupported_technology(): void
    {
        $this->postJson('/api/homepass', ['address' => 'X', 'technology' => 'WIMAX'])
            ->assertCreated()
            ->assertJsonPath('policyWarnings.0.ruleId', 'R-RLM-HP-001');
    }

    public function test_package_lifecycle_create_version_activate(): void
    {
        $package = $this->postJson('/api/packages', [
            'code' => 'TRIPLE-PLAY',
            'name' => 'Triple Play',
            'billing_frequency_days' => 30,
        ])->assertCreated()->json('id');

        $this->postJson("/api/packages/{$package}/versions", [
            'price' => 4999.00,
            'currency' => 'KES',
            'effective_from' => now()->toDateString(),
        ])->assertCreated();

        $this->postJson("/api/packages/{$package}/activate")
            ->assertOk()
            ->assertJsonPath('status', 'ACTIVE');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PackageActivated']);
    }

    public function test_activate_without_version_is_rejected(): void
    {
        $package = $this->postJson('/api/packages', ['code' => 'EMPTY', 'name' => 'Empty'])->json('id');

        $this->postJson("/api/packages/{$package}/activate")
            ->assertStatus(422)
            ->assertJsonPath('errorCode', 'PACKAGE_NO_VERSION');
    }

    public function test_tech_region_and_homepass_serviceability(): void
    {
        $this->postJson('/api/tech-regions', [
            'tech_region_id' => 'KE-NRB-KAREN',
            'display_name_primary' => 'Karen',
            'region_type' => 'NEIGHBORHOOD',
        ])->assertCreated();

        $homepass = $this->postJson('/api/homepass', [
            'address' => '123 Karen Road',
            'tech_region_id' => 'KE-NRB-KAREN',
            'technology' => 'GPON',
        ])->assertCreated()->json('homepass.id');

        $this->patchJson("/api/homepass/{$homepass}/status", ['status' => 'SERVICEABLE'])
            ->assertOk()
            ->assertJsonPath('status', 'SERVICEABLE')
            ->assertJsonPath('has_been_active', true);

        $this->getJson('/api/homepass?techRegionId=KE-NRB-KAREN&status=SERVICEABLE')
            ->assertOk()
            ->assertJsonPath('totalElements', 1);
    }

    public function test_read_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->getJson('/api/packages')->assertForbidden();
    }
}
