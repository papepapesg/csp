<?php

namespace Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Models\Customer;
use Tests\TestCase;

/**
 * Locks in the tenant-isolation + privilege-ceiling hardening so these Critical fixes can't
 * silently regress: no cross-operator read via id/header/param, and no RBAC self-escalation.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function customerFor(string $operator, string $name): Customer
    {
        // Force the operator only for the create (the trait pins operator_code to it), then clear
        // so the request under test relies purely on lazy auth-derived context (no leftover force).
        Context::setOperatorCode($operator);
        $c = Customer::query()->create(['customer_id' => Id::make('cust'), 'type' => 'RES', 'name' => $name, 'primary_msisdn' => '+2547'.random_int(10000000, 99999999)]);
        Context::setOperatorCode(null);

        return $c;
    }

    public function test_a_user_cannot_read_another_operators_data_by_id_header_or_param(): void
    {
        $wtz = $this->customerFor('WTZ', 'Tanzania Telecom');
        $wik = $this->customerFor('WIK', 'Kenya Telecom');

        // A non-super WIK operator user (holds customer.read, NOT platform.cross_operator).
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->givePermissionTo('customer.read');
        Sanctum::actingAs($user);

        // List returns only the user's operator — even with a spoofed header/param (API serializes
        // camelCase: customerId).
        $ids = collect($this->getJson('/api/customers', ['X-Operator-Code' => 'WTZ'])->assertOk()->json('items'))->pluck('customerId');
        $this->assertTrue($ids->contains($wik->customer_id), 'own-operator row should be listed');
        $this->assertFalse($ids->contains($wtz->customer_id), 'cross-operator row must not leak via header');
        $ids2 = collect($this->getJson('/api/customers?operatorCode=WTZ')->assertOk()->json('items'))->pluck('customerId');
        $this->assertFalse($ids2->contains($wtz->customer_id), 'cross-operator row must not leak via query param');

        // Direct fetch of another operator's record by id → 404 (scoped route binding).
        $this->getJson("/api/customers/{$wtz->customer_id}")->assertNotFound();
        // Own operator's record resolves fine.
        $this->getJson("/api/customers/{$wik->customer_id}")->assertOk();
    }

    public function test_rbac_manage_cannot_self_escalate_to_super_admin(): void
    {
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('RBAC_ADMIN'); // holds rbac.manage but is NOT super admin
        Sanctum::actingAs($user);

        $this->postJson("/api/rbac/users/{$user->uid}/roles", ['roles' => ['SUPER_ADMIN']])
            ->assertStatus(403)->assertJsonPath('errorCode', 'ROLE_CEILING');

        // And it cannot grant a permission it doesn't hold.
        $this->putJson('/api/rbac/roles/RBAC_ADMIN/permissions', ['permissions' => ['payment.reverse']])
            ->assertStatus(403)->assertJsonPath('errorCode', 'PERMISSION_CEILING');
    }
}
