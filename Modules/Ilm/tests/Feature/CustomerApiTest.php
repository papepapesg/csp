<?php

namespace Modules\Ilm\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Models\Customer;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAgent(array $roles = ['CUSTOMER_CARE_AGENT', 'SALES_AGENT']): User
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole($roles);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_create_customer_returns_201_and_emits_event(): void
    {
        $this->actingAsAgent();

        $response = $this->postJson('/api/customers', [
            'type' => 'RES',
            'name' => 'Jane Mwangi',
            'primary_msisdn' => '+254712345678',
            'email' => 'jane@example.com',
            'identification_type_1' => 'NATIONAL_ID',
            'identification_number_1' => '28471929',
        ]);

        $response->assertCreated()
            ->assertJsonPath('kycStatus', 'PENDING')
            ->assertJsonPath('name', 'Jane Mwangi');

        $this->assertStringStartsWith('cust_', $response->json('customerId'));
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CustomerCreated']);
    }

    public function test_customer_search_by_msisdn(): void
    {
        $this->actingAsAgent();
        Customer::factory()->create(['primary_msisdn' => '+254700111222', 'operator_code' => 'WIK']);

        $response = $this->getJson('/api/customers/search?key=msisdn&value=%2B254700111222');

        $response->assertOk()
            ->assertJsonPath('totalElements', 1)
            ->assertJsonStructure(['items', 'page', 'size', 'totalElements', 'totalPages']);
    }

    public function test_kyc_two_step_approval_flow(): void
    {
        $this->actingAsAgent(['SUPER_ADMIN']);
        $customer = Customer::factory()->create(['kyc_status' => 'PENDING']);

        // Final approval before L1 must be rejected by the rule.
        $this->postJson("/api/customers/{$customer->customer_id}/kyc/final-approve")
            ->assertStatus(422)
            ->assertJsonPath('errorCode', 'KYC_L1_REQUIRED');

        $this->postJson("/api/customers/{$customer->customer_id}/kyc/l1-approve", ['approverRole' => 'L1_SUPERVISOR'])
            ->assertOk()
            ->assertJsonPath('kycStatus', 'L1_APPROVED');

        $this->postJson("/api/customers/{$customer->customer_id}/kyc/final-approve")
            ->assertOk()
            ->assertJsonPath('kycStatus', 'APPROVED');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CustomerKycApproved']);
    }

    public function test_create_account_for_customer(): void
    {
        $this->actingAsAgent(['SUPER_ADMIN']);
        $customer = Customer::factory()->create();

        $response = $this->postJson('/api/customer-accounts', [
            'customer_id' => $customer->customer_id,
            'service_address' => '123 Karen Road, Nairobi',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'INACTIVE')
            ->assertJsonPath('customerId', $customer->customer_id);

        $this->assertStringStartsWith('acct_', $response->json('accountId'));
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CustomerAccountCreated']);
    }

    public function test_requires_permission(): void
    {
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN'); // no customer.create
        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'type' => 'RES', 'name' => 'X', 'primary_msisdn' => '+254700000000',
        ])->assertForbidden();
    }
}
