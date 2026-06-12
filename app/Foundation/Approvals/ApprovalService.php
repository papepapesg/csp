<?php

namespace App\Foundation\Approvals;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;

/**
 * EM-CFG-04 approval engine. A module calls request() before a sensitive action;
 * the matching approval_definition decides whether approval is needed (and below a
 * threshold it auto-approves). decide() records an approval/rejection; once the
 * required approvals are gathered the request flips to APPROVED. Config-driven: a
 * new approval policy is a definition row, not code.
 */
class ApprovalService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data entity_type, action?, entity_ref?, amount?, payload?, requested_by? */
    public function request(array $data): ApprovalRequest
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $def = ApprovalDefinition::query()
            ->where('operator_code', $operator)
            ->where('entity_type', $data['entity_type'])
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('action')->orWhere('action', $data['action'] ?? null))
            ->orderByRaw('action is null') // prefer action-specific
            ->first();

        $amount = isset($data['amount']) ? (float) $data['amount'] : null;
        $needsApproval = $def
            && ($def->threshold_amount === null || ($amount !== null && $amount >= (float) $def->threshold_amount));

        $request = ApprovalRequest::query()->create([
            'request_id' => Id::make('appr'),
            'operator_code' => $operator,
            'entity_type' => $data['entity_type'],
            'action' => $data['action'] ?? null,
            'entity_ref' => $data['entity_ref'] ?? null,
            'amount' => $amount,
            'payload' => $data['payload'] ?? null,
            'approver_roles' => $def?->approver_roles,
            'required_approvals' => $def?->required_approvals ?? 1,
            'allow_requester' => (bool) ($def?->allow_requester ?? false), // APR-6 snapshot from policy
            'status' => $needsApproval ? ApprovalRequest::PENDING : ApprovalRequest::AUTO_APPROVED,
            'requested_by' => $data['requested_by'] ?? null,
            'decided_at' => $needsApproval ? null : now(),
        ]);

        $this->emit($request, $needsApproval ? 'ApprovalRequested' : 'ApprovalAutoApproved');

        return $request;
    }

    public function decide(ApprovalRequest $request, bool $approve, ?\App\Models\User $actorUser = null, ?string $reason = null): ApprovalRequest
    {
        if ($request->status !== ApprovalRequest::PENDING) {
            throw DomainException::conflict('Approval request is not pending.');
        }
        $actor = $actorUser?->uid;

        // APR-6 (segregation of duties): the requester cannot approve their own request
        // unless the policy explicitly allows it (allow_requester = true, config).
        if (! $request->allow_requester && $actor !== null && $actor === $request->requested_by) {
            throw new DomainException('SELF_APPROVAL_NOT_ALLOWED', 'The requester cannot approve their own request.', 403);
        }
        // APR-5: the approver must hold one of the policy's approver roles (SUPER_ADMIN always may).
        $roles = $request->approver_roles ?? [];
        if ($roles !== [] && ! ($actorUser && ($actorUser->hasRole('SUPER_ADMIN') || $actorUser->hasAnyRole($roles)))) {
            throw new DomainException('APPROVER_NOT_AUTHORIZED', 'You do not hold a role permitted to act on this approval.', 403);
        }
        // APR-7: every action writes an immutable decision record.
        $this->recordDecision($request, $approve ? 'APPROVE' : 'REJECT', $actor, $reason);

        if (! $approve) {
            $request->update(['status' => ApprovalRequest::REJECTED, 'decided_by' => $actor, 'decision_reason' => $reason, 'decided_at' => now()]);
            $this->emit($request, 'ApprovalRejected');

            return $request->refresh();
        }

        $count = $request->approvals_count + 1;
        $final = $count >= $request->required_approvals;
        $request->update([
            'approvals_count' => $count,
            'status' => $final ? ApprovalRequest::APPROVED : ApprovalRequest::PENDING,
            'decided_by' => $actor,
            'decision_reason' => $reason,
            'decided_at' => $final ? now() : null,
        ]);
        if ($final) {
            $this->emit($request, 'ApprovalApproved');
        }

        return $request->refresh();
    }

    /** APR-7: immutable per-action decision audit. */
    private function recordDecision(ApprovalRequest $request, string $decision, ?string $actor, ?string $comment): void
    {
        \Illuminate\Support\Facades\DB::table('approval_decision')->insert([
            'decision_id' => Id::make('appdec'),
            'request_id' => $request->request_id,
            'operator_code' => $request->operator_code,
            'decision' => $decision,
            'actor_user_id' => $actor,
            'comment' => $comment,
            'decided_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function emit(ApprovalRequest $request, string $type): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: 'platform.approvals',
            payload: ['requestId' => $request->request_id, 'entityType' => $request->entity_type, 'status' => $request->status],
            aggregateType: 'ApprovalRequest',
            aggregateId: $request->request_id,
        ));
    }
}
