<?php

namespace Modules\Catalog\Tests\Feature;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Modules\Catalog\Database\Seeders\HomePassStatusSeeder;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Models\HomePassStatusCode;
use Modules\Catalog\Models\NetworkNode;
use Modules\Catalog\Models\TechRegion;
use Modules\Workforce\Models\Contractor;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(CatalogPolicySeeder::class); // rules.service-catalog / rules.homepass-catalog
        $this->seed(HomePassStatusSeeder::class); // homepass_status_code catalog
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

    public function test_homepass_bulk_import_and_geo_enrich(): void
    {
        // Bulk import: partial-reject — the duplicate of row 1 is reported but doesn't block the batch.
        $row = ['address' => '5 Bulk St', 'country' => 'KE', 'road_name' => 'Bulk Street', 'building_number' => '5'];
        $res = $this->postJson('/api/homepass/bulk-import', ['rows' => [
            $row,
            ['address' => '6 Bulk St', 'country' => 'KE', 'road_name' => 'Bulk Street', 'building_number' => '6'],
            $row, // duplicate of row 1 (H-1)
        ]])->assertOk();
        $res->assertJsonPath('imported', 2)->assertJsonPath('rejected', 1)
            ->assertJsonPath('rowResults.2.error', 'HOMEPASS_DUPLICATE_ADDRESS');

        // GIS disabled by default → enrich-from-geo is a no-op (degrades, never errors).
        $hp = $this->postJson('/api/homepass', ['address' => '9 Geo Rd', 'latitude' => -1.29, 'longitude' => 36.82])->assertCreated()->json('homepass.id');
        $this->postJson("/api/homepass/{$hp}/enrich-from-geo")->assertOk()->assertJsonPath('id', $hp);
    }

    public function test_tech_coverage_lifecycle_and_rules(): void
    {
        $this->postJson('/api/tech-contractor-skills', ['code' => 'INSTALLATION', 'name' => 'Install'])->assertCreated();
        // C-3: contractor skills must exist in the catalog.
        $this->postJson('/api/tech-contractors', ['code' => 'X', 'name' => 'X', 'skills' => ['BOGUS']])
            ->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_SKILL');
        $con = $this->postJson('/api/tech-contractors', ['code' => 'ACME', 'name' => 'Acme', 'skills' => ['INSTALLATION']])->assertCreated()->json('contractor_id');

        $this->postJson('/api/tech-regions', ['tech_region_id' => 'KE-COV', 'display_name_primary' => 'Cov', 'region_type' => 'NEIGHBORHOOD'])->assertCreated();
        TechRegion::query()->where('tech_region_id', 'KE-COV')->update(['status' => 'DRAFT']);

        // T-7: cannot activate a region with no active contractor.
        $this->postJson('/api/tech-regions/KE-COV/activate')->assertStatus(422)->assertJsonPath('errorCode', 'REGION_HAS_NO_CONTRACTOR');
        // A-1: assignment skills must subset the contractor's skills.
        $this->postJson('/api/tech-regions/KE-COV/contractors', ['contractor_id' => $con, 'skills' => ['MAINTENANCE']])
            ->assertStatus(422)->assertJsonPath('errorCode', 'ASSIGNMENT_SKILLS_INVALID');
        $this->postJson('/api/tech-regions/KE-COV/contractors', ['contractor_id' => $con, 'skills' => ['INSTALLATION']])->assertCreated();
        // Now activation succeeds (T-7).
        $this->postJson('/api/tech-regions/KE-COV/activate')->assertOk()->assertJsonPath('status', 'ACTIVE');

        // T-3: cannot retire while an active HomePass references the region.
        $hp = $this->postJson('/api/homepass', ['address' => '1 Cov Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        DB::table('homepass_tech_region')->insert(['homepass_id' => $hp, 'tech_region_ref' => 'KE-COV', 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'ACT'])->assertOk(); // is_active
        $this->postJson('/api/tech-regions/KE-COV/retire')->assertStatus(422)->assertJsonPath('errorCode', 'REGION_REFERENCED');
    }

    public function test_homepass_structured_address_uniqueness_correction_and_place_id(): void
    {
        $addr = ['address' => '12 Wood Ave', 'country' => 'KE', 'region_l1' => 'Nairobi County', 'city' => 'Nairobi',
            'road_name' => 'Wood Avenue', 'building_number' => '12', 'apartment_number' => 'M18'];

        $hp = $this->postJson('/api/homepass', $addr)->assertCreated()->json('homepass.id');

        // R-RLM-CFG-01-H-1: the same full address tuple cannot be created twice.
        $this->postJson('/api/homepass', $addr)->assertStatus(422)->assertJsonPath('errorCode', 'HOMEPASS_DUPLICATE_ADDRESS');

        // R-RLM-CFG-01-H-14: correcting an address field is audited via HomePassAddressCorrected.
        $this->patchJson("/api/homepass/{$hp}/address", ['building_name' => 'Finewood Apartments', 'google_place_id' => 'ChIJ_PLACE_1'])->assertOk();
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'HomePassAddressCorrected']);

        // R-RLM-CFG-01-H-18: google_place_id is immutable once set.
        $this->patchJson("/api/homepass/{$hp}/address", ['google_place_id' => 'ChIJ_DIFFERENT'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'GOOGLE_PLACE_ID_IMMUTABLE');
    }

    public function test_network_node_catalog_parent_chain_and_referenced_guard(): void
    {
        // A node chain OLT ← SPLITTER ← FAT.
        $this->postJson('/api/network-nodes', ['code' => 'OLT-1', 'type' => 'OLT', 'name' => 'OLT One'])->assertCreated();
        $this->postJson('/api/network-nodes', ['code' => 'SPL-1', 'type' => 'SPLITTER', 'name' => 'Splitter', 'parent_node_code' => 'OLT-1'])->assertCreated();
        $fat = $this->postJson('/api/network-nodes', ['code' => 'FAT-1', 'type' => 'FAT', 'name' => 'FAT', 'parent_node_code' => 'SPL-1'])->assertCreated()->json('node_id');

        // R-RLM-CFG-01-N-3: invalid type rejected.
        $this->postJson('/api/network-nodes', ['code' => 'X', 'type' => 'WIMAX', 'name' => 'x'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'INVALID_NODE_TYPE');
        // R-RLM-CFG-01-N-4: unknown parent rejected; a cycle (OLT-1 parented under FAT-1) rejected.
        $this->postJson('/api/network-nodes', ['code' => 'Y', 'type' => 'ONT', 'name' => 'y', 'parent_node_code' => 'NOPE'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_PARENT_NODE');
        NetworkNode::query()->where('code', 'OLT-1')->update(['parent_node_code' => 'FAT-1']);
        $this->postJson('/api/network-nodes', ['code' => 'Z', 'type' => 'ONT', 'name' => 'z', 'parent_node_code' => 'OLT-1'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'NODE_PARENT_CYCLE');
        NetworkNode::query()->where('code', 'OLT-1')->update(['parent_node_code' => null]);

        // R-RLM-CFG-01-N-5: a node referenced by a HomePass network_path cannot be retired.
        $hp = $this->postJson('/api/homepass', ['address' => '1 Node Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        $this->patchJson("/api/homepass/{$hp}/network-path", ['nodes' => [['type' => 'FAT', 'code' => 'FAT-1', 'role' => 'passive']]])->assertOk();
        $this->postJson("/api/network-nodes/{$fat}/retire")->assertStatus(422)->assertJsonPath('errorCode', 'NODE_REFERENCED');
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
        $supported = HomePass::find($hp)->services_supported;
        $this->assertEqualsCanonicalizing(['DATA', 'IPTV_MULTICAST', 'VOICE'], $supported);
    }

    public function test_eligible_contractors_for_a_homepass_skill(): void
    {
        $hp = $this->postJson('/api/homepass', ['address' => '9 Route Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        $region = 'KE-NRB-ROUTE';

        $con = Contractor::query()->create([
            'operator_code' => 'WIK', 'code' => 'ACME_FIBER', 'name' => 'Acme Fiber', 'skills' => ['INSTALLATION', 'MAINTENANCE'], 'status' => 'ACTIVE',
        ]);
        DB::table('homepass_tech_region')->insert(['homepass_id' => $hp, 'tech_region_ref' => $region, 'created_at' => now(), 'updated_at' => now()]);
        // Per-region assignment scopes the contractor to INSTALLATION only in this region (R-RLM-CFG-01-A-1).
        DB::table('tech_region_contractor')->insert([
            'tech_region_id' => $region, 'tech_contractor_id' => $con->contractor_id, 'operator_code' => 'WIK',
            'skills' => json_encode(['INSTALLATION']), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson("/api/homepass/{$hp}/eligible-contractors?skill=INSTALLATION")
            ->assertOk()->assertJsonPath('items.0.contractor_id', $con->contractor_id);
        // The assignment doesn't scope MAINTENANCE in this region → none eligible.
        $this->getJson("/api/homepass/{$hp}/eligible-contractors?skill=MAINTENANCE")
            ->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_homepass_transition_is_maker_checker_when_policy_gates_it(): void
    {
        // An EM-CFG-04 policy gates transitions into requires_approval_to_enter codes (H-5).
        ApprovalDefinition::defineChain('WIK', 'HOMEPASS_STATUS_TRANSITION', null, [
            ['approver_kind' => 'ROLE', 'approver_roles' => ['SUPER_ADMIN']],
        ]);
        $hp = $this->postJson('/api/homepass', ['address' => '1 Gate Rd', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');

        // Proposing RFS (requires_approval_to_enter) does NOT flip — it awaits approval.
        $this->patchJson("/api/homepass/{$hp}/status", ['status' => 'RFS'])->assertOk()->assertJsonPath('status', 'DRAFT');
        $this->assertDatabaseHas('approval_request', ['entity_type' => 'HOMEPASS_STATUS_TRANSITION', 'entity_ref' => $hp, 'status' => 'PENDING']);

        // A separate approver grants it; the listener applies the transition.
        $reqId = ApprovalRequest::query()->where('entity_ref', $hp)->value('request_id');
        $approver = User::factory()->create(['operator_code' => 'WIK']);
        $approver->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($approver);
        $this->postJson("/api/approvals/{$reqId}/decide", ['approve' => true])->assertOk()->assertJsonPath('status', 'APPROVED');
        Artisan::call('sophix:outbox:dispatch');

        $this->assertSame('RFS', HomePass::find($hp)->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'HomePassReachedSellable']);
    }

    public function test_homepass_status_is_a_config_catalog_with_semantic_flags(): void
    {
        $this->postJson('/api/tech-regions', ['tech_region_id' => 'KE-NRB-X', 'display_name_primary' => 'X', 'region_type' => 'NEIGHBORHOOD'])->assertCreated();
        $hp = $this->postJson('/api/homepass', ['address' => '1 X Rd', 'tech_region_id' => 'KE-NRB-X', 'technology' => 'GPON'])->assertCreated()->json('homepass.id');
        $count = fn () => DB::table('outbox_events')->where('event_type', 'HomePassReachedSellable')->count();

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
        HomePassStatusCode::query()->create(['operator_code' => 'WIK', 'code' => 'LIVE', 'is_sellable' => true, 'active' => true]);
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
