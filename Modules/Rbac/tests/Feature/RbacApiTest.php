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

    public function test_user_scope_assignment_and_enforcement_check(): void
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);

        // Grant a franchise scope — the user may act on that franchise only.
        $this->postJson("/api/rbac/users/{$user->uid}/scopes", ['scopeType' => 'FRANCHISE', 'scopeValue' => 'fr-NRB-002', 'scopeLabel' => 'Nairobi 002'])
            ->assertCreated();
        $this->assertDatabaseHas('rbac_user_scope_assignment', ['auth_user_id' => $user->uid, 'scope_type' => 'FRANCHISE', 'scope_value' => 'fr-NRB-002', 'active' => true]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'RbacUserScopeAssigned']);

        // The enforcement check modules call: in scope for the granted franchise, out for another.
        $this->getJson("/api/rbac/users/{$user->uid}/within-scope?scopeType=FRANCHISE&scopeValue=fr-NRB-002")->assertOk()->assertJsonPath('within', true);
        $this->getJson("/api/rbac/users/{$user->uid}/within-scope?scopeType=FRANCHISE&scopeValue=fr-MSA-001")->assertOk()->assertJsonPath('within', false);

        // Effective access now reports scopes alongside roles + permissions.
        $this->getJson("/api/rbac/users/{$user->uid}/effective-access")->assertOk()->assertJsonPath('scopes.0.type', 'FRANCHISE');
    }

    public function test_global_scope_passes_any_check(): void
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $this->postJson("/api/rbac/users/{$user->uid}/scopes", ['scopeType' => 'GLOBAL', 'scopeValue' => '*'])->assertCreated();

        $this->getJson("/api/rbac/users/{$user->uid}/within-scope?scopeType=TECH_REGION&scopeValue=anything")->assertOk()->assertJsonPath('within', true);
    }

    public function test_scope_revocation_removes_access(): void
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $scope = $this->postJson("/api/rbac/users/{$user->uid}/scopes", ['scopeType' => 'TEAM', 'scopeValue' => 'team-1'])->assertCreated()->json('scope_assignment_id');

        $this->postJson("/api/rbac/scopes/{$scope}/revoke")->assertOk();
        $this->getJson("/api/rbac/users/{$user->uid}/within-scope?scopeType=TEAM&scopeValue=team-1")->assertOk()->assertJsonPath('within', false);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'RbacUserScopeRevoked']);
    }

    public function test_frontend_action_matrix_filters_by_permission(): void
    {
        $this->postJson('/api/rbac/frontend-actions', ['app_code' => 'FE-APP-01', 'action_code' => 'backoffice.tickets.create', 'action_type' => 'BUTTON', 'display_name' => 'Create Ticket', 'required_permission_code' => 'ticket.create'])->assertCreated();
        $this->postJson('/api/rbac/frontend-actions', ['app_code' => 'FE-APP-01', 'action_code' => 'backoffice.billing.adjust', 'action_type' => 'BUTTON', 'display_name' => 'Adjust', 'required_permission_code' => 'invoice.manage'])->assertCreated();

        $agent = User::factory()->create(['operator_code' => 'WIK']);
        $agent->assignRole('CUSTOMER_CARE_AGENT'); // has ticket.create, not invoice.manage

        $nav = $this->getJson("/api/rbac/users/{$agent->uid}/navigation?appCode=FE-APP-01")->assertOk()->json('actions');
        $codes = collect($nav)->pluck('actionCode')->all();
        $this->assertContains('backoffice.tickets.create', $codes);
        $this->assertNotContains('backoffice.billing.adjust', $codes); // hidden — lacks the permission
    }

    public function test_permission_metadata_marks_scope_required(): void
    {
        $this->putJson('/api/rbac/permissions/franchise.manage/meta', ['module_code' => 'EM-01', 'risk_level' => 'HIGH', 'scope_required' => true])->assertOk();
        $this->assertDatabaseHas('rbac_permission_meta', ['permission_code' => 'franchise.manage', 'scope_required' => true, 'risk_level' => 'HIGH']);
    }
}
