<?php

namespace Modules\Notification\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Events\OutboxEventPublished;
use Modules\Notification\Icn\Services\StaffNotificationService;

/**
 * EM-CFG-04 -> ICN-01 bridge. When the approval engine opens a pending request — or advances the
 * chain to a new stage — notify the CURRENT stage's approver group (its approver_roles) through
 * ICN-01 so a human knows a decision awaits them. Without this, every approval request sat silent
 * until someone happened to look. Keyed to the request id + stage (idempotent on replay; a fresh
 * key per stage so each level's approvers are alerted in turn).
 *
 * A stage that targets a NAMED USER (approver_kind = USER, e.g. an invited "director") has no role
 * group to resolve here; that person sees the pending request in their approval queue. Direct
 * per-user channel delivery is a follow-up (see as-built spine open items).
 */
class NotifyApproversOnApprovalRequested
{
    public function __construct(private readonly StaffNotificationService $staff) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if (! in_array($event->event_type, ['ApprovalRequested', 'ApprovalStageAdvanced'], true)) {
            return;
        }
        $request = ApprovalRequest::query()->find($event->payload['requestId'] ?? null);
        // The working snapshot (approver_roles) always reflects the active stage; a USER stage or a
        // role-less policy has no group to notify, so it is skipped (the approver acts from the queue).
        $roles = $request?->approver_roles ?? [];
        if (! $request || $roles === []) {
            return;
        }
        $stage = (int) ($request->current_stage ?? 1);

        // Notify each distinct approver group for the active stage (RBAC role = ICN candidate group).
        foreach (array_unique($roles) as $group) {
            $this->staff->dispatch([
                'operatorCode' => $request->operator_code,
                'templateCode' => 'approval-needed',
                'candidateGroup' => $group,
                'templateVariables' => ['entityType' => $request->entity_type, 'requestId' => $request->request_id, 'stage' => $stage],
                'sourceModule' => 'APPROVALS',
                'sourceBusinessKey' => $request->request_id,
                'urgency' => 'high',
            ], idempotencyKey: "appr-notif-{$request->request_id}-s{$stage}-{$group}");
        }
    }
}
