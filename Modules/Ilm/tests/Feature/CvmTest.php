<?php

namespace Modules\Ilm\Tests\Feature;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Ilm\Database\Seeders\CvmPolicySeeder;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Cvm\Models\CvmActivity;
use Modules\Ilm\Cvm\Models\CvmOfferInstance;
use Modules\Ilm\Cvm\Services\CvmEvaluationService;
use Modules\Ilm\Cvm\Services\CvmOfferService;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Tests\TestCase;

/**
 * EM-03 CVM: signal evaluation → segmentation → activity, offer proposal with EM-CFG-04
 * approval, and acceptance calling SIP-03 (not DIS directly) with an outcome recorded.
 */
class CvmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(CvmPolicySeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);

        foreach (['CUS-1', 'CUS-2', 'CUS-3'] as $id) {
            Customer::query()->create([
                'customer_id' => $id, 'operator_code' => 'WIK', 'name' => 'Test', 'type' => 'RES', 'primary_msisdn' => '+254712345678',
            ]);
        }
    }

    public function test_high_risk_dunning_customer_enters_retention_segment_with_activity(): void
    {
        // Test scenario 1+2: dunning event updates profile, high-risk → retention segment + activity.
        $res = $this->postJson('/api/cvm/customers/CUS-1/evaluate', [
            'reason' => 'DUNNING_EVENT', 'sourceEventRef' => 'dunning-evt-1',
            'signals' => ['dunningLevel' => 2, 'complaintCount90d' => 3],
        ])->assertOk();

        $res->assertJsonPath('segments.0', 'RETENTION_HIGH_RISK');
        $this->assertNotNull($res->json('activityId'));
        $this->assertDatabaseHas('cvm_customer_signal_profile', ['customer_id' => 'CUS-1', 'dunning_level' => 2, 'churn_risk_score' => 80]);
        $this->assertDatabaseHas('cvm_segment_membership', ['customer_id' => 'CUS-1', 'segment_code' => 'RETENTION_HIGH_RISK', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('cvm_activity', ['customer_id' => 'CUS-1', 'activity_type' => 'PAYMENT_RECOVERY', 'priority' => 'HIGH', 'status' => 'OPEN']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CvmCustomerEvaluated']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CvmActivityCreated']);
        // CUST-INT-01: the contact attempt is on the interaction timeline.
        $this->assertDatabaseHas('customer_interaction', ['customer_id' => 'CUS-1', 'reason' => 'CVM_PAYMENT_RECOVERY']);
    }

    public function test_activity_creation_is_idempotent_by_source_event(): void
    {
        // Test scenario 3.
        $a = app(CvmEvaluationService::class)->evaluate('WIK', 'CUS-2', ['dunningLevel' => 2, 'complaintCount90d' => 2], 'DUNNING', 'evt-X');
        $b = app(CvmEvaluationService::class)->evaluate('WIK', 'CUS-2', ['dunningLevel' => 2, 'complaintCount90d' => 2], 'DUNNING', 'evt-X');

        $this->assertSame($a['activityId'], $b['activityId']);
        $this->assertSame(1, CvmActivity::where('customer_id', 'CUS-2')->count());
    }

    public function test_retention_offer_over_threshold_requires_em_cfg_04_approval(): void
    {
        // Test scenario 4: an offer over threshold goes through EM-CFG-04.
        $offer = app(CvmOfferService::class)->propose([
            'operatorCode' => 'WIK', 'customerId' => 'CUS-1', 'offerType' => 'RETENTION_DISCOUNT',
            'discountPercent' => 25, 'discountRef' => 'DISC-RET-25',
        ]);

        $this->assertSame(CvmOfferInstance::PENDING_APPROVAL, $offer->status);
        $this->assertNotNull($offer->approval_request_id);
        $this->assertDatabaseHas('approval_request', ['request_id' => $offer->approval_request_id, 'entity_type' => 'CVM_OFFER', 'status' => 'PENDING']);

        // It cannot be accepted while pending approval.
        $this->postJson("/api/cvm-offers/{$offer->offer_instance_id}/accept", [])->assertStatus(409);
    }

    public function test_granting_the_approval_resumes_the_offer_so_it_can_be_accepted(): void
    {
        // An over-threshold offer parks in PENDING_APPROVAL.
        $offer = app(CvmOfferService::class)->propose([
            'operatorCode' => 'WIK', 'customerId' => 'CUS-1', 'offerType' => 'RETENTION_DISCOUNT',
            'discountPercent' => 25, 'discountRef' => 'DISC-RET-25',
        ]);
        $this->assertSame(CvmOfferInstance::PENDING_APPROVAL, $offer->status);

        // The EM-CFG-04 chain is a hierarchy: the CVM manager clears stage 1, THEN the named
        // director (an invited login, no platform role) signs off stage 2 — distinct approvers.
        $svc = app(ApprovalService::class);
        $manager = User::factory()->create(['operator_code' => 'WIK']);
        $manager->assignRole('CVM_MANAGER');
        $director = User::query()->where('email', 'cvm.director@wik.sn')->firstOrFail();

        $request = ApprovalRequest::query()->find($offer->approval_request_id);
        $svc->decide($request, true, $manager);                 // stage 1 → advances, still PENDING
        $this->assertSame('PENDING', $request->refresh()->status);
        $this->assertSame(2, (int) $request->current_stage);
        $svc->decide($request, true, $director);                // stage 2 → APPROVED

        // Dispatching the outbox fires ResumeCvmOfferOnApproval, which releases the offer.
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();
        $this->assertSame(CvmOfferInstance::PROPOSED, $offer->refresh()->status);

        // It is now acceptable (previously it stayed stuck in PENDING_APPROVAL forever).
        $this->postJson("/api/cvm-offers/{$offer->offer_instance_id}/accept", ['customerConsentRef' => 'consent-9'])
            ->assertOk()->assertJsonPath('status', 'APPLIED');
    }

    public function test_rejecting_the_approval_closes_the_offer(): void
    {
        $offer = app(CvmOfferService::class)->propose([
            'operatorCode' => 'WIK', 'customerId' => 'CUS-1', 'offerType' => 'RETENTION_DISCOUNT',
            'discountPercent' => 25, 'discountRef' => 'DISC-RET-25',
        ]);

        // A reject at the first stage (the CVM manager) fails the whole chain.
        $manager = User::factory()->create(['operator_code' => 'WIK']);
        $manager->assignRole('CVM_MANAGER');
        $request = ApprovalRequest::query()->find($offer->approval_request_id);
        app(ApprovalService::class)->decide($request, false, $manager, 'too generous');
        $this->artisan('sophix:outbox:dispatch')->assertSuccessful();

        $this->assertSame(CvmOfferInstance::REJECTED, $offer->refresh()->status);
    }

    public function test_accepted_discount_offer_calls_sip03_and_records_outcome(): void
    {
        // Test scenario 5: accepted discount offer calls SIP-03 discount assignment (not DIS-OP).
        $offer = app(CvmOfferService::class)->propose([
            'operatorCode' => 'WIK', 'customerId' => 'CUS-1', 'offerType' => 'RETENTION_DISCOUNT',
            'discountPercent' => 10, 'discountRef' => 'DISC-RET-10', 'campaignCode' => 'RET-10PCT-3M',
        ]);
        $this->assertSame(CvmOfferInstance::PROPOSED, $offer->status); // 10% is at/under threshold, no approval

        $this->postJson("/api/cvm-offers/{$offer->offer_instance_id}/accept", ['customerConsentRef' => 'consent-1'])
            ->assertOk()->assertJsonPath('status', 'APPLIED');

        // SIP-03 discount assignment created; EM-03 didn't write the discount itself.
        $this->assertDatabaseHas('discount_assignment', ['discount_code' => 'DISC-RET-10', 'scope' => 'CUSTOMER', 'scope_ref' => 'CUS-1']);
        $this->assertDatabaseHas('cvm_outcome', ['customer_id' => 'CUS-1', 'outcome_code' => 'ACCEPTED', 'owning_module_ref_type' => 'DISCOUNT_ASSIGNMENT']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CvmOfferAccepted']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CvmOfferApplied']);
    }

    public function test_healthy_account_is_an_upsell_candidate(): void
    {
        $res = $this->postJson('/api/cvm/customers/CUS-3/evaluate', [
            'signals' => ['dunningLevel' => 0, 'activeSubscriptionCount' => 2],
        ])->assertOk();

        $res->assertJsonPath('segments.0', 'UPSELL');
        $this->assertDatabaseHas('cvm_activity', ['customer_id' => 'CUS-3', 'activity_type' => 'UPSELL_OFFER']);
    }
}
