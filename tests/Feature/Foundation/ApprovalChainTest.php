<?php

namespace Tests\Feature\Foundation;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * EM-CFG-04 staged approval chains: ordered hierarchy (a stage opens only after the previous one
 * clears), each stage targets a ROLE or a named USER, a stage's quorum needs DISTINCT approvers,
 * and a reject anywhere fails the whole chain.
 */
class ApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function requester(): User
    {
        $u = User::factory()->create(['operator_code' => 'WIK', 'email' => 'requester@wik.sn']);
        $u->assignRole('CUSTOMER_CARE_AGENT'); // can raise, holds no approver role

        return $u;
    }

    private function withRole(string $role, string $email): User
    {
        $u = User::factory()->create(['operator_code' => 'WIK', 'email' => $email]);
        $u->assignRole($role);

        return $u;
    }

    private function twoStageManagerThenDirector(): ApprovalDefinition
    {
        // Stage 1: a platform ROLE (the "manager"). Stage 2: a named USER (the "director", no role).
        return ApprovalDefinition::defineChain('WIK', 'ADJUSTMENT_HV', null, [
            ['name' => 'Manager review', 'approver_kind' => 'ROLE', 'approver_roles' => ['BILLING_LEAD']],
            ['name' => 'Director sign-off', 'approver_kind' => 'USER', 'approver_email' => 'director@wik.sn'],
        ]);
    }

    public function test_chain_is_ordered_manager_then_director(): void
    {
        $this->twoStageManagerThenDirector();
        $requester = $this->requester();
        $manager = $this->withRole('BILLING_LEAD', 'manager@wik.sn');
        $director = User::factory()->create(['operator_code' => 'WIK', 'email' => 'director@wik.sn']); // invited, NO role

        Sanctum::actingAs($requester);
        $req = $this->postJson('/api/approvals', ['entity_type' => 'ADJUSTMENT_HV'], ['Idempotency-Key' => 'hv1'])
            ->assertCreated()->assertJsonPath('status', 'PENDING')->assertJsonPath('current_stage', 1)->json();
        $id = $req['request_id'];

        // The director cannot jump ahead of the manager: stage 1 is a ROLE stage they don't fill.
        Sanctum::actingAs($director);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertStatus(403)->assertJsonPath('errorCode', 'APPROVER_NOT_AUTHORIZED');

        // Manager clears stage 1 → request stays PENDING but advances to stage 2.
        Sanctum::actingAs($manager);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'PENDING')->assertJsonPath('current_stage', 2);

        // The manager may NOT act on the director's stage.
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertStatus(403)->assertJsonPath('errorCode', 'APPROVER_NOT_AUTHORIZED');

        // The named director (an invited user with no platform role) clears the final stage → APPROVED.
        Sanctum::actingAs($director);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'APPROVED');

        // Both stages are audited with their stage number.
        $this->assertDatabaseHas('approval_decision', ['request_id' => $id, 'stage_sequence' => 1, 'actor_user_id' => $manager->uid]);
        $this->assertDatabaseHas('approval_decision', ['request_id' => $id, 'stage_sequence' => 2, 'actor_user_id' => $director->uid]);
    }

    public function test_reject_at_first_stage_fails_the_whole_chain(): void
    {
        $this->twoStageManagerThenDirector();
        $requester = $this->requester();
        $manager = $this->withRole('BILLING_LEAD', 'manager@wik.sn');

        Sanctum::actingAs($requester);
        $id = $this->postJson('/api/approvals', ['entity_type' => 'ADJUSTMENT_HV'], ['Idempotency-Key' => 'hv2'])
            ->assertCreated()->json('request_id');

        Sanctum::actingAs($manager);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => false, 'reason' => 'not justified'])
            ->assertOk()->assertJsonPath('status', 'REJECTED');

        // The chain never reached stage 2.
        $this->assertDatabaseMissing('approval_decision', ['request_id' => $id, 'stage_sequence' => 2]);
    }

    public function test_stage_quorum_requires_distinct_approvers(): void
    {
        ApprovalDefinition::defineChain('WIK', 'DUAL_SIGN', null, [
            ['approver_kind' => 'ROLE', 'approver_roles' => ['BILLING_LEAD'], 'required_approvals' => 2],
        ]);

        $requester = $this->requester();
        $lead1 = $this->withRole('BILLING_LEAD', 'lead1@wik.sn');
        $lead2 = $this->withRole('BILLING_LEAD', 'lead2@wik.sn');

        Sanctum::actingAs($requester);
        $id = $this->postJson('/api/approvals', ['entity_type' => 'DUAL_SIGN'], ['Idempotency-Key' => 'q1'])
            ->assertCreated()->json('request_id');

        // First lead approves → 1 of 2, still pending.
        Sanctum::actingAs($lead1);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'PENDING');

        // The SAME lead cannot fill the second slot — a distinct approver is required.
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertStatus(409)->assertJsonPath('errorCode', 'DUPLICATE_STAGE_APPROVER');

        // A different lead completes the quorum → APPROVED.
        Sanctum::actingAs($lead2);
        $this->postJson("/api/approvals/{$id}/decide", ['approve' => true])
            ->assertOk()->assertJsonPath('status', 'APPROVED');
    }

    public function test_invite_provisions_a_named_approver_login(): void
    {
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($admin);

        $res = $this->postJson('/api/approval-approvers/invite', ['email' => 'newdir@wik.sn', 'name' => 'New Director'])
            ->assertCreated()->assertJsonPath('status', 'INVITED')->json();
        $this->assertDatabaseHas('users', ['email' => 'newdir@wik.sn', 'status' => 'INVITED', 'uid' => $res['uid']]);

        // Idempotent on email within the operator.
        $this->postJson('/api/approval-approvers/invite', ['email' => 'newdir@wik.sn', 'name' => 'New Director'])
            ->assertCreated()->assertJsonPath('uid', $res['uid']);
    }
}
