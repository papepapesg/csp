<?php

namespace Modules\Catalog\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Catalog\Models\PackageLaunchPlan;
use Modules\Catalog\Services\PackageLaunchService;

/**
 * SIP-02 R-SIP-02-05 maker-checker: when an EM-CFG-04 approval for a package launch
 * plan is granted or rejected, advance the launch plan (APPROVED→activate path /
 * REJECTED). Mirrors ApplyHomePassTransitionOnApproval — the outbox publish of an
 * ApprovalApproved/ApprovalRejected event drives the local state change.
 */
class ApplyPackageLaunchApproval
{
    public function __construct(private readonly PackageLaunchService $launch) {}

    public function handle(OutboxEventPublished $published): void
    {
        $type = $published->event->event_type;
        if ($type !== 'ApprovalApproved' && $type !== 'ApprovalRejected') {
            return;
        }
        $request = ApprovalRequest::query()->find($published->event->payload['requestId'] ?? null);
        if (! $request || $request->entity_type !== 'PACKAGE_LAUNCH_PLAN') {
            return;
        }
        $plan = PackageLaunchPlan::query()->find($request->entity_ref);
        if (! $plan) {
            return;
        }
        $outcome = $type === 'ApprovalApproved' ? 'APPROVED' : 'REJECTED';
        $this->launch->applyApprovalOutcome($plan, $outcome, $request->decided_by ?? null);
    }
}
