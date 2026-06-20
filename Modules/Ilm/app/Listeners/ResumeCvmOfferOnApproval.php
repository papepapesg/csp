<?php

namespace Modules\Ilm\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Ilm\Models\CvmOfferInstance;
use Modules\Ilm\Services\CvmOfferService;

/**
 * EM-03 / EM-CFG-04 §4 maker-checker resume: a high-value retention offer parks in
 * PENDING_APPROVAL while its EM-CFG-04 approval is pending. When that approval is finally
 * granted or rejected, advance the offer (APPROVED -> PROPOSED so it can be accepted;
 * REJECTED -> REJECTED). Without this the approved offer stayed stuck forever, since
 * accept() refuses a PENDING_APPROVAL offer. Mirrors Catalog's ApplyPackageLaunchApproval.
 */
class ResumeCvmOfferOnApproval
{
    public function __construct(private readonly CvmOfferService $offers) {}

    public function handle(OutboxEventPublished $published): void
    {
        $type = $published->event->event_type;
        if ($type !== 'ApprovalApproved' && $type !== 'ApprovalRejected') {
            return;
        }
        $request = ApprovalRequest::query()->find($published->event->payload['requestId'] ?? null);
        if (! $request || $request->entity_type !== 'CVM_OFFER') {
            return;
        }
        $offer = CvmOfferInstance::query()->find($request->entity_ref);
        if (! $offer) {
            return;
        }
        $this->offers->applyApprovalOutcome($offer, $type === 'ApprovalApproved' ? 'APPROVED' : 'REJECTED', $request->decided_by ?? null);
    }
}
