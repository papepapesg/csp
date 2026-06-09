<?php

namespace Modules\Rbac\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RbacApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($admin);
    }

    public function test_runtime_role_creation_and_permission_matrix(): void
    {
        $this->postJson('/api/rbac/roles', ['code' => 'REGIONAL_MANAGER', 'permissions' => ['customer.read', 'report.view']])
            ->assertCreated()->assertJsonPath('code', 'REGIONAL_MANAGER');

        // Add a brand-new permission to the catalog at runtime + attach it.
        $this->putJson('/api/rbac/roles/REGIONAL_MANAGER/permissions', ['permissions' => ['customer.read', 'region.manage']])
            ->assertOk()
            ->assertJsonFragment(['region.manage']);

        $this->getJson('/api/rbac/permissions')->assertOk()->assertJsonFragment(['region.manage']);
    }

    public function test_assign_roles_and_effective_access(): void
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);

        $this->postJson("/api/rbac/users/{$user->uid}/roles", ['roles' => ['BILLING_LEAD']])
            ->assertOk()->assertJsonFragment(['BILLING_LEAD']);

        $this->getJson("/api/rbac/users/{$user->uid}/effective-access")
            ->assertOk()
            ->assertJsonPath('roles.0', 'BILLING_LEAD')
            ->assertJsonFragment(['invoice.manage']);
    }

    public function test_rbac_changes_are_audited(): void
    {
        // EM-CFG-03 §8.8: catalog edits, matrix syncs and assignments leave an
        // immutable audit row with before/after state and the acting admin.
        $this->postJson('/api/rbac/roles', ['code' => 'AUDIT_ROLE', 'permissions' => ['report.view']])->assertCreated();
        $this->putJson('/api/rbac/roles/AUDIT_ROLE/permissions', ['permissions' => ['report.view', 'customer.read']])->assertOk();
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $this->postJson("/api/rbac/users/{$user->uid}/roles", ['roles' => ['AUDIT_ROLE']])->assertOk();

        $this->assertDatabaseHas('rbac_change_audit', ['change_type' => 'ROLE_CREATED', 'target_id' => 'AUDIT_ROLE']);
        $this->assertDatabaseHas('rbac_change_audit', ['change_type' => 'ROLE_PERMISSIONS_SYNCED', 'target_id' => 'AUDIT_ROLE']);
        $this->assertDatabaseHas('rbac_change_audit', ['change_type' => 'USER_ROLE_ASSIGNED', 'target_id' => $user->uid]);

        // The feed is queryable per target.
        $items = collect($this->getJson('/api/rbac/audit?targetType=ROLE&targetId=AUDIT_ROLE')->assertOk()->json('items'));
        $this->assertCount(2, $items);
        $synced = $items->firstWhere('change_type', 'ROLE_PERMISSIONS_SYNCED');
        $this->assertSame(['permissions' => ['report.view', 'customer.read']], $synced['after_json']);
        $this->assertSame(['permissions' => ['report.view']], $synced['before_json']);
    }

    public function test_user_directory_for_assignment_screen(): void
    {
        User::factory()->create(['name' => 'Grace Wanjiku', 'operator_code' => 'WIK'])->assignRole('DISPATCHER');

        $items = $this->getJson('/api/rbac/users?q=wanjiku')->assertOk()->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(['DISPATCHER'], $items[0]['roles']);
    }

    public function test_requires_rbac_manage(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->getJson('/api/rbac/roles')->assertForbidden();
    }
}
