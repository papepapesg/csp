<?php

namespace Modules\WorkOrder\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\WorkOrder\Database\Seeders\FieldAuditPolicySeeder;
use Tests\TestCase;

class FieldAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(FieldAuditPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_clean_equipment_audit_auto_closes(): void
    {
        $id = $this->postJson('/api/field-audits', ['kind' => 'EQUIPMENT', 'target_ref' => 'eqi_1'])->assertCreated()->json('audit_id');

        $this->postJson("/api/field-audits/{$id}/findings", ['findings' => ['serialMatch' => true, 'physicalDamage' => false]])
            ->assertOk()->assertJsonPath('status', 'CLOSED')->assertJsonPath('severity', 'OK');
    }

    public function test_kyc_identity_mismatch_routes_to_review(): void
    {
        $id = $this->postJson('/api/field-audits', ['kind' => 'KYC', 'target_ref' => 'cust_1'])->assertCreated()->json('audit_id');

        $res = $this->postJson("/api/field-audits/{$id}/findings", ['findings' => ['identityMatch' => false, 'addressMatch' => true]])
            ->assertOk()->assertJsonPath('severity', 'CRITICAL')->assertJsonPath('status', 'UNDER_REVIEW');
        $this->assertNotNull($res->json('approval_request_id'));
        $this->assertDatabaseHas('approval_request', ['entity_type' => 'FIELD_AUDIT', 'status' => 'PENDING']);
    }
}
