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

    public function test_kyc_documents_are_stored_through_the_file_foundation(): void
    {
        $this->actingAsAgent(['CUSTOMER_CARE_AGENT']);
        \Illuminate\Support\Facades\Storage::fake('local');
        $customer = Customer::factory()->create(['operator_code' => 'WIK']);

        // Post the binary to the KYC documents endpoint — it lands in FOUNDATION_FILE_STORAGE.
        $doc = $this->post("/api/customers/{$customer->customer_id}/kyc/documents", [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('id_front.jpg'),
            'document_type' => 'NATIONAL_ID_FRONT',
        ])->assertStatus(201)->json();

        // The bytes are in the foundation registry (with a storage path); ILM holds the reference + hash.
        $this->assertDatabaseHas('file_object', ['file_id' => $doc['file_id'], 'owner_type' => 'CUSTOMER_KYC', 'owner_id' => $customer->customer_id]);
        $this->assertDatabaseHas('customer_kyc_document', ['document_id' => $doc['document_id'], 'customer_id' => $customer->customer_id, 'document_type' => 'NATIONAL_ID_FRONT', 'storage_path' => $doc['storage_path']]);
        $this->assertNotNull($doc['content_hash']); // SHA-256 from the foundation (R-ILM-K-6)

        // A second ID-front document supersedes the first (R-ILM-K-8, never hard-deleted).
        $doc2 = $this->post("/api/customers/{$customer->customer_id}/kyc/documents", [
            'file' => \Illuminate\Http\UploadedFile::fake()->image('id_front_v2.jpg'),
            'document_type' => 'NATIONAL_ID_FRONT',
        ])->assertStatus(201)->json();
        $this->assertDatabaseHas('customer_kyc_document', ['document_id' => $doc['document_id'], 'superseded_by_id' => $doc2['document_id']]);

        // Referencing a non-existent foundation file is rejected.
        $this->postJson("/api/customers/{$customer->customer_id}/kyc/documents", ['file_id' => 'file_missing', 'document_type' => 'PASSPORT'])
            ->assertStatus(404)->assertJsonPath('errorCode', 'KYC_DOCUMENT_FILE_NOT_FOUND');
    }

    public function test_kyc_approval_authority_is_config_driven(): void
    {
        // KYC runs on the EM-CFG-04 engine: kyc_approval_role is the per-stage approver config (R-ILM-K-3),
        // turned into a two-stage chain (L1 → final) when KYC opens. The chain is FROZEN onto the request
        // at that moment, so the operator's config must be in force before the flow starts — here the
        // operator points the final stage at the supervisor role too, by editing config, no code change.
        $this->actingAsAgent(['CUSTOMER_CARE_AGENT']);
        $this->seed(\Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder::class);
        \Illuminate\Support\Facades\DB::table('kyc_approval_role')
            ->where('operator_code', 'WIK')->where('approval_level', 2)->update(['required_role' => 'CUSTOMER_CARE_SUPERVISOR']);
        $customer = Customer::factory()->create(['kyc_status' => 'PENDING', 'operator_code' => 'WIK']);

        // A plain agent lacks the configured L1 role → the engine refuses the stage (R-ILM-K-3).
        $this->postJson("/api/customers/{$customer->customer_id}/kyc/l1-approve")
            ->assertStatus(403)->assertJsonPath('errorCode', 'KYC_APPROVER_ROLE_REQUIRED');

        // The configured supervisor role clears L1 (stage 1)...
        $this->actingAsAgent(['CUSTOMER_CARE_SUPERVISOR']);
        $this->postJson("/api/customers/{$customer->customer_id}/kyc/l1-approve")
            ->assertOk()->assertJsonPath('kycStatus', 'L1_APPROVED');

        // ...and, with the final stage pointed at the same role, clears the final stage → APPROVED.
        $this->postJson("/api/customers/{$customer->customer_id}/kyc/final-approve")
            ->assertOk()->assertJsonPath('kycStatus', 'APPROVED');
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
