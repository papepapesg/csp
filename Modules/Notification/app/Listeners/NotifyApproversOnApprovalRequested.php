<?php

namespace Modules\Notification\Listeners;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalStage;
use App\Foundation\Events\OutboxEventPublished;
use App\Models\User;
use Modules\Notification\Icn\Services\StaffNotificationService;

/**
 * EM-CFG-04 -> ICN-01 bridge. When the approval engine opens a pending request — or advances the
 * chain to a new stage — notify the CURRENT stage's approver(s) through ICN-01 so a human knows a
 * decision awaits them. Keyed to the request id + stage (idempotent on replay; a fresh key per
 * stage so each level's approvers are alerted in turn).
 *
 * - A ROLE stage notifies its `approver_roles` as ICN candidate groups (group → members).
 * - A USER stage (a named approver, e.g. an invited "director" with no platform role) is sent a
 *   DIRECT message to that person's address (their email here) via `dispatchDirect` — so the named
 *   approver is reached even though they belong to no group.
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
        if (! $request) {
            return;
        }
        $seq = (int) ($request->current_stage ?? 1);
        $stage = $this->currentStage($request, $seq);
        $vars = ['entityType' => $request->entity_type, 'requestId' => $request->request_id, 'stage' => $seq];

        if (($stage['approver_kind'] ?? ApprovalStage::ROLE) === ApprovalStage::USER) {
            // Named approver → reach them directly at their address (no group to resolve).
            $email = $stage['approver_email'] ?? $this->lookupEmail($stage['approver_user_ref'] ?? null, $request->operator_code);
            if (! $email) {
                return;
            }
            $this->staff->dispatchDirect([
                'operatorCode' => $request->operator_code,
                'templateCode' => 'approval-needed',
                'templateVariables' => $vars,
                'recipients' => [['channel' => 'EMAIL', 'address' => $email]],
                'sourceModule' => 'APPROVALS',
                'sourceBusinessKey' => $request->request_id,
                'urgency' => 'high',
            ], idempotencyKey: "appr-notif-{$request->request_id}-s{$seq}-user");

            return;
        }

        // ROLE stage: notify each distinct approver group (RBAC role = ICN candidate group).
        $roles = $stage['approver_roles'] ?? ($request->approver_roles ?? []);
        foreach (array_unique($roles ?? []) as $group) {
            $this->staff->dispatch([
                'operatorCode' => $request->operator_code,
                'templateCode' => 'approval-needed',
                'candidateGroup' => $group,
                'templateVariables' => $vars,
                'sourceModule' => 'APPROVALS',
                'sourceBusinessKey' => $request->request_id,
                'urgency' => 'high',
            ], idempotencyKey: "appr-notif-{$request->request_id}-s{$seq}-{$group}");
        }
    }

    /** @return array<string,mixed> the active stage from the request's frozen chain snapshot. */
    private function currentStage(ApprovalRequest $request, int $seq): array
    {
        foreach ($request->stages_snapshot ?? [] as $stage) {
            if ((int) ($stage['sequence'] ?? 0) === $seq) {
                return $stage;
            }
        }

        return ['approver_kind' => ApprovalStage::ROLE, 'approver_roles' => $request->approver_roles ?? []];
    }

    private function lookupEmail(?string $uid, string $operator): ?string
    {
        if (! $uid) {
            return null;
        }

        return User::query()->where('operator_code', $operator)->where('uid', $uid)->value('email');
    }
}
