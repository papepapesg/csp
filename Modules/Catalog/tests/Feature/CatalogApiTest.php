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
        $this->seed(\Modules\Catalog\Database\Seeders\HomePassStatusSeeder::class); // homepass_status_code catalog
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

    public function test_homepass_network_path_derives_services_and_endpoints(): void
    {
        $hp = $this->postJson('/api/homepass', ['address' => '7 Topo Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');

        // The node chain: OLT (data+iptv, service_management) + VOIPSWITCH (voice, service_management) + ONT (termination).
        $this->patchJson("/api/homepass/{$hp}/network-path", [
            'captureMode' => 'PRE_INSTALLATION',
            'nodes' => [
                ['type' => 'OLT', 'code' => 'OLT-NRB-WTL-01', 'role' => 'service_management', 'port' => '1/2/3'],
                ['type' => 'VOIPSWITCH', 'code' => 'VOIPSWITCH-NRB-01', 'role' => 'service_management', 'port' => 'EXT-5421'],
                ['type' => 'SPLITTER', 'code' => 'SPL-1', 'role' => 'passive'],
                ['type' => 'ONT', 'code' => 'ONT-SN-1', 'role' => 'termination'],
            ],
        ])->assertOk()
            // service_management_endpoints: the {nodeCode,port} each gateway must address (R-RLM-CFG-01-H-11).
            ->assertJsonPath('service_management_endpoints.data.nodeCode', 'OLT-NRB-WTL-01')
            ->assertJsonPath('service_management_endpoints.data.port', '1/2/3')
            ->assertJsonPath('service_management_endpoints.voice.nodeCode', 'VOIPSWITCH-NRB-01')
            ->assertJsonPath('service_management_endpoints.iptv_multicast.nodeCode', 'OLT-NRB-WTL-01');

        // services_supported derived from node types (R-RLM-CFG-01-H-12).
        $supported = \Modules\Catalog\Models\HomePass::find($hp)->services_supported;
        $this->assertEqualsCanonicalizing(['DATA', 'IPTV_MULTICAST', 'VOICE'], $supported);
    }

    public function test_eligible_contractors_for_a_homepass_skill(): void
    {
        $hp = $this->postJson('/api/homepass', ['address' => '9 Route Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        $region = 'KE-NRB-ROUTE';

        $con = \Modules\Catalog\Models\TechContractor::query()->create([
            'operator_code' => 'WIK', 'code' => 'ACME_FIBER', 'name' => 'Acme Fiber', 'skills' => ['INSTALLATION', 'MAINTENANCE'], 'status' => 'ACTIVE',
        ]);
        \Illuminate\Support\Facades\DB::table('homepass_tech_region')->insert(['homepass_id' => $hp, 'tech_region_ref' => $region, 'created_at' => now(), 'updated_at' => now()]);
        // Per-region assignment scopes the contractor to INSTALLATION only in this region (R-RLM-CFG-01-A-1).
        \Illuminate\Support\Facades\DB::table('tech_region_contractor')->insert([
            'tech_region_id' => $region, 'tech_contractor_id' => $con->contractor_id, 'operator_code' => 'WIK',
            'skills' => json_encode(['INSTALLATION']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/homepass/{$hp}/eligible-contractors?skill=INSTALLATION")
            ->assertOk()->assertJsonPath('items.0.contractor_id', $con->contractor_id);
        // The assignment doesn't scope MAINTENANCE in this region → none eligible.
        $this->getJson("/api/homepass/{$hp}/eligible-contractors?skill=MAINTENANCE")
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_homepass_status_is_a_config_catalog_with_semantic_flags(): void
    {
        $this->postJson('/api/tech-regions', ['tech_region_id' => 'KE-NRB-X', 'display_name_primary' => 'X', 'region_type' => 'NEIGHBORHOOD'])->assertCreated();
        $hp = $this->postJson('/api/homepass', ['address' => '1 X Rd', 'tech_region_id' => 'KE-NRB-X', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        $count = fn () => \Illuminate\Support\Facades\DB::table('outbox_events')->where('event_type', 'HomePassReachedSellable')->count();

        // First transition into a sellable status (RFS) fires the lead-notify event (R-RLM-CFG-01-H-6).
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'RFS'])->assertOk()->assertJsonPath('has_been_sellable', true);
        $this->assertSame(1, $count());

        // Re-entering a sellable status does NOT re-emit (latched): ACT (active, not sellable) then RFS again.
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'ACT'])->assertOk();
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'RFS'])->assertOk();
        $this->assertSame(1, $count());

        // Serviceability lookup returns the sellable HomePass — read by the is_sellable flag, not a literal code.
        $this->getJson('/api/homepass/eligible?techRegionId=KE-NRB-X')->assertOk()->assertJsonPath('items.0.id', $hp);

        // An operator adds a custom sellable code (config, no code change) — eligibility honours it.
        \Modules\Catalog\Models\HomePassStatusCode::query()->create(['operator_code' => 'WIK', 'code' => 'LIVE', 'is_sellable' => true, 'active' => true]);
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'LIVE'])->assertOk();
        $this->getJson('/api/homepass/eligible?techRegionId=KE-NRB-X')->assertOk()->assertJsonPath('items.0.id', $hp);

        // An unknown code is rejected — status is catalog-governed, not a free string.
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'BOGUS'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_HOMEPASS_STATUS');
    }

    public function test_read_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->getJson('/api/packages')->assertForbidden();
    }
}
