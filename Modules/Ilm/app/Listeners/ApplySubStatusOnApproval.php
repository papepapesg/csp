<?php

namespace Modules\Ilm\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;

/**
 * EM-CFG-04 maker-checker resume for a customer sub-status change. A requires_approval sub-status
 * transition parks an EM-CFG-04 request (single approver or a chain — the engine decides). When the
 * approval is granted, apply the held transition; the request's payload carries the change to make.
 * Mirrors ResumeCvmOfferOnApproval / Catalog's ApplyPackageLaunchApproval. (A rejection simply leaves
 * the account on its current sub-status — nothing to undo.)
 */
class ApplySubStatusOnApproval
{
    public function __construct(private readonly AccountService $accounts) {}

    public function handle(OutboxEventPublished $published): void
    {
        if ($published->event->event_type !== 'ApprovalApproved') {
            return;
        }
        $request = ApprovalRequest::query()->find($published->event->payload['requestId'] ?? null);
        if (! $request || $request->entity_type !== 'CUSTOMER_SUB_STATUS') {
            return;
        }
        $account = CustomerAccount::query()->find($request->entity_ref);
        if (! $account) {
            return;
        }
        $change = $request->payload['change'] ?? [];
        $change['_subStatusApproved'] = true;            // skip the gate — the approval is the gate
        $change['approval_reference'] = $request->request_id;
        $this->accounts->update($account, $change);
    }
}
