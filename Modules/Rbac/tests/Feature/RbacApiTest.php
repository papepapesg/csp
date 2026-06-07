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

    public function test_requires_rbac_manage(): void
    {
        $user = User::factory()->create();
        $user->assignRole('CUSTOMER_CARE_AGENT');
        Sanctum::actingAs($user);

        $this->getJson('/api/rbac/roles')->assertForbidden();
    }
}
