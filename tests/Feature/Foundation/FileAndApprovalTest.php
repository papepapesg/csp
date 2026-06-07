<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FileAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    public function test_file_upload_and_download(): void
    {
        Storage::fake('local');
        $res = $this->post('/api/files', [
            'file' => UploadedFile::fake()->create('id.pdf', 12, 'application/pdf'),
            'owner_type' => 'Customer', 'owner_id' => 'cust_1', 'category' => 'KYC_ID',
        ]);
        $res->assertCreated();
        $fileId = $res->json('file_id');
        $this->assertDatabaseHas('file_object', ['file_id' => $fileId, 'category' => 'KYC_ID']);
        $this->getJson("/api/files/{$fileId}")->assertOk()->assertJsonPath('filename', 'id.pdf');
    }

    public function test_approval_auto_approves_below_threshold_and_requires_above(): void
    {
        ApprovalDefinition::query()->create([
            'definition_id' => 'appd_1', 'operator_code' => 'WIK', 'entity_type' => 'ADJUSTMENT',
            'threshold_amount' => 1000, 'approver_roles' => ['BILLING_LEAD'], 'required_approvals' => 1, 'active' => true,
        ]);

        // Below threshold -> auto-approved.
        $this->postJson('/api/approvals', ['entity_type' => 'ADJUSTMENT', 'amount' => 500], ['Idempotency-Key' => 'a1'])
            ->assertCreated()->assertJsonPath('status', 'AUTO_APPROVED');

        // At/above threshold -> pending; then decide.
        $req = $this->postJson('/api/approvals', ['entity_type' => 'ADJUSTMENT', 'amount' => 5000], ['Idempotency-Key' => 'a2'])
            ->assertCreated()->assertJsonPath('status', 'PENDING')->json();
        $this->postJson("/api/approvals/{$req['request_id']}/decide", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
    }
}
