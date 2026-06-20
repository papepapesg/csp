<?php

namespace Modules\Ilm\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Modules\Catalog\Models\DiscountAssignment;
use Modules\Ilm\Events\CvmEvents;
use Modules\Ilm\Models\CvmActivity;
use Modules\Ilm\Models\CvmOfferInstance;
use Modules\Ilm\Models\CvmOutcome;

/**
 * EM-03 offer lifecycle (DD §5.3/5.4). Proposing an offer routes high-value retention discounts
 * through EM-CFG-04 approval (rules.cvm.offer decides the threshold). Accepting an offer does NOT
 * apply the discount directly — EM-03 calls the owning module (SIP-03 discount assignment) and
 * records the result as a cvm_outcome (boundary rule). Emits Proposed/Accepted/Applied.
 */
class CvmOfferService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly ApprovalService $approvals,
        private readonly CvmActivityService $activities,
    ) {}

    /** @param array<string,mixed> $data */
    public function propose(array $data): CvmOfferInstance
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        // rules.cvm.offer: offerType + discountPercent -> {requireApproval, approvalPolicy}.
        $decision = $this->rules->evaluate('rules.cvm.offer', [
            'offerType' => $data['offerType'],
            'discountPercent' => (float) ($data['discountPercent'] ?? 0),
        ]);

        $offer = CvmOfferInstance::query()->create([
            'operator_code' => $operator,
            'activity_id' => $data['activityId'] ?? null,
            'customer_id' => $data['customerId'],
            'subscription_id' => $data['subscriptionId'] ?? null,
            'offer_type' => $data['offerType'],
            'campaign_code' => $data['campaignCode'] ?? null,
            'discount_ref' => $data['discountRef'] ?? null,
            'discount_percent' => $data['discountPercent'] ?? null,
            'status' => CvmOfferInstance::PROPOSED,
            'expires_at' => now()->addDays((int) ($data['validDays'] ?? 7)),
        ]);

        // EM-CFG-04 approval for exceptional / high-value offers.
        if (! empty($decision['requireApproval'])) {
            $req = $this->approvals->request([
                'operator_code' => $operator,
                'entity_type' => 'CVM_OFFER',
                'action' => $decision['approvalPolicy'] ?? 'CVM_HIGH_VALUE_RETENTION_OFFER',
                'entity_ref' => $offer->offer_instance_id,
                'payload' => ['offerType' => $offer->offer_type, 'discountPercent' => $offer->discount_percent],
                'requested_by' => $data['requestedBy'] ?? null,
            ]);
            $offer->update([
                'approval_request_id' => $req->request_id,
                'status' => $req->status === ApprovalRequest::PENDING ? CvmOfferInstance::PENDING_APPROVAL : CvmOfferInstance::PROPOSED,
            ]);
        }

        $this->emit(CvmEvents::OFFER_PROPOSED, $offer);

        return $offer->refresh();
    }

    /**
     * Accept an offer: confirm any required approval, then call the owning module (SIP-03
     * discount assignment) and record the outcome. The discount is applied by SIP-03, not EM-03.
     */
    public function accept(CvmOfferInstance $offer, ?string $acceptedByUserId = null, ?string $consentRef = null): CvmOfferInstance
    {
        if ($offer->status === CvmOfferInstance::PENDING_APPROVAL) {
            throw DomainException::conflict('Offer is awaiting EM-CFG-04 approval and cannot be accepted yet.');
        }
        if ($offer->status !== CvmOfferInstance::PROPOSED) {
            throw DomainException::conflict('Offer is not in a proposable state.');
        }

        $offer->update(['status' => CvmOfferInstance::ACCEPTED]);
        $this->activities->writeInteraction($offer->customer_id, 'CVM_OFFER_ACCEPTED', "Offer {$offer->offer_instance_id} accepted; consent={$consentRef}");
        $this->emit(CvmEvents::OFFER_ACCEPTED, $offer, ['acceptedByUserId' => $acceptedByUserId]);

        // Boundary: call SIP-03 to assign the discount; EM-03 never writes the discount itself.
        $refType = $refId = null;
        if ($offer->offer_type === 'RETENTION_DISCOUNT' && $offer->discount_ref) {
            $assignment = DiscountAssignment::query()->create([
                'assignment_id' => Id::make('dasg'),
                'discount_code' => $offer->discount_ref,
                'scope' => 'CUSTOMER',
                'scope_ref' => $offer->customer_id,
                'campaign_code' => $offer->campaign_code,
            ]);
            $refType = 'DISCOUNT_ASSIGNMENT';
            $refId = $assignment->assignment_id;
            $offer->update(['status' => CvmOfferInstance::APPLIED]);
            $this->emit(CvmEvents::OFFER_APPLIED, $offer, ['owningModuleRefType' => $refType, 'owningModuleRefId' => $refId]);
        }

        // Record the outcome (DD §4.5). If the offer hangs off an activity, close it too.
        if ($offer->activity_id && ($activity = CvmActivity::query()->find($offer->activity_id))) {
            $this->activities->close($activity, 'ACCEPTED', "Offer {$offer->offer_instance_id} applied", $offer->offer_instance_id);
            CvmOutcome::query()->where('offer_instance_id', $offer->offer_instance_id)
                ->update(['owning_module_ref_type' => $refType, 'owning_module_ref_id' => $refId]);
        } else {
            CvmOutcome::query()->create([
                'operator_code' => $offer->operator_code, 'offer_instance_id' => $offer->offer_instance_id, 'customer_id' => $offer->customer_id,
                'outcome_code' => 'ACCEPTED', 'owning_module_ref_type' => $refType, 'owning_module_ref_id' => $refId,
                'notes' => "Offer {$offer->offer_instance_id} accepted",
            ]);
        }

        return $offer->refresh();
    }

    public function reject(CvmOfferInstance $offer, ?string $notes = null): CvmOfferInstance
    {
        $offer->update(['status' => CvmOfferInstance::REJECTED]);
        $this->activities->writeInteraction($offer->customer_id, 'CVM_OFFER_REJECTED', $notes);

        return $offer->refresh();
    }

    /**
     * EM-CFG-04 ownership callback (DD_EM-CFG-04 §4 step 9): when the offer's approval is
     * finally decided, resume the offer. APPROVED releases it to PROPOSED (now acceptable);
     * REJECTED closes it. Without this the offer stayed in PENDING_APPROVAL forever, since
     * accept() refuses a pending offer. Only acts on an offer still awaiting approval.
     */
    public function applyApprovalOutcome(CvmOfferInstance $offer, string $outcome, ?string $decidedBy = null): CvmOfferInstance
    {
        if ($offer->status !== CvmOfferInstance::PENDING_APPROVAL) {
            return $offer; // already resumed/closed — idempotent.
        }
        if ($outcome === 'APPROVED') {
            $offer->update(['status' => CvmOfferInstance::PROPOSED]);
            $this->emit(CvmEvents::OFFER_PROPOSED, $offer, ['approvalDecidedBy' => $decidedBy]);

            return $offer->refresh();
        }

        return $this->reject($offer, "EM-CFG-04 approval rejected by {$decidedBy}");
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, CvmOfferInstance $offer, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: CvmEvents::TOPIC,
            payload: ['offerInstanceId' => $offer->offer_instance_id, 'customerId' => $offer->customer_id, 'offerType' => $offer->offer_type, 'status' => $offer->status, 'operatorCode' => $offer->operator_code] + $extra,
            aggregateType: 'CvmOfferInstance', aggregateId: $offer->offer_instance_id,
        ));
    }
}
