<?php

namespace Modules\Catalog\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Catalog\Network\Models\HomePass;
use Modules\Catalog\Plm\Services\CatalogService;

/**
 * RLM-CFG-01 R-RLM-CFG-01-H-5 maker-checker: when an EM-CFG-04 approval for a HomePass status
 * transition is granted, apply the proposed status (bypassing the approval gate this time).
 */
class ApplyHomePassTransitionOnApproval
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function handle(OutboxEventPublished $published): void
    {
        if ($published->event->event_type !== 'ApprovalApproved') {
            return;
        }
        $request = ApprovalRequest::query()->find($published->event->payload['requestId'] ?? null);
        if (! $request || $request->entity_type !== 'HOMEPASS_STATUS_TRANSITION') {
            return;
        }
        $homepass = HomePass::query()->find($request->entity_ref);
        if (! $homepass) {
            return;
        }
        $target = $request->payload['targetStatus'] ?? $request->action;
        $this->catalog->changeHomePassStatus($homepass, $target, bypassApproval: true);
    }
}
