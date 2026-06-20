<?php

namespace Modules\Notification\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Notification\Icn\Services\StaffNotificationService;

/**
 * EM-CFG-04 -> ICN-01 bridge. When the approval engine opens a pending request, notify the
 * approver group (the policy's approver_roles) through ICN-01 so a human knows a decision awaits
 * them. Without this, every approval request sat silent until someone happened to look. Keyed to
 * the request id (idempotent on replay). A policy with no approver_roles has no specific group to
 * notify, so it is skipped (anyone with the permission may act).
 */
class NotifyApproversOnApprovalRequested
{
    public function __construct(private readonly StaffNotificationService $staff) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'ApprovalRequested') {
            return;
        }
        $request = ApprovalRequest::query()->find($event->payload['requestId'] ?? null);
        $roles = $request?->approver_roles ?? [];
        if (! $request || $roles === []) {
            return;
        }

        // Notify each distinct approver group (RBAC role used as the ICN candidate group).
        foreach (array_unique($roles) as $group) {
            $this->staff->dispatch([
                'operatorCode' => $request->operator_code,
                'templateCode' => 'approval-needed',
                'candidateGroup' => $group,
                'templateVariables' => ['entityType' => $request->entity_type, 'requestId' => $request->request_id],
                'sourceModule' => 'APPROVALS',
                'sourceBusinessKey' => $request->request_id,
                'urgency' => 'high',
            ], idempotencyKey: "appr-notif-{$request->request_id}-{$group}");
        }
    }
}
