<?php

namespace App\Foundation\Approvals;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * EM-CFG-04 approval engine. A module calls request() before a sensitive action; the matching
 * approval_definition decides whether approval is needed (and below a threshold it auto-approves).
 *
 * Approval is an ORDERED CHAIN of stages, not a flat count. Each stage targets a platform ROLE
 * (any of approver_roles) or a specific named USER (a person with an invited login, e.g. a
 * "director" who holds no platform role), and carries its own quorum + self-approval toggle.
 * decide() acts on the CURRENT stage only; a stage's quorum must be met by DISTINCT approvers
 * before the next stage opens; the final stage flips the request to APPROVED. A reject at any
 * stage fails the whole chain. Config-driven: a new policy (and its chain) is data, not code.
 *
 * approval_definition is purely the policy header (WHEN approval is needed); the approver config (WHO,
 * in what order) lives only in approval_stage. The request freezes the resolved chain in
 * stages_snapshot and tracks progress via current_stage + approvals_count.
 */
class ApprovalService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data entity_type, action?, entity_ref?, amount?, payload?, requested_by?, stages? */
    public function request(array $data): ApprovalRequest
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $amount = isset($data['amount']) ? (float) $data['amount'] : null;

        if (! empty($data['stages'])) {
            // The caller resolved the chain itself — used when WHO/how-many is decided at request time
            // rather than by a static approval_definition (e.g. a rules engine answers "N approvals" per
            // proposal). We gate on the supplied chain directly; the same frozen-snapshot rules apply.
            $needsApproval = true;
            $chain = $this->normaliseStages($data['stages']);
        } else {
            $def = ApprovalDefinition::query()
                ->where('operator_code', $operator)
                ->where('entity_type', $data['entity_type'])
                ->where('active', true)
                ->where(fn ($q) => $q->whereNull('action')->orWhere('action', $data['action'] ?? null))
                ->orderByRaw('action is null') // prefer action-specific
                ->first();

            $needsApproval = $def
                && ($def->threshold_amount === null || ($amount !== null && $amount >= (float) $def->threshold_amount));

            $chain = $needsApproval ? $this->resolveChain($def) : [];
        }

        $request = ApprovalRequest::query()->create([
            'request_id' => Id::make('appr'),
            'operator_code' => $operator,
            'entity_type' => $data['entity_type'],
            'action' => $data['action'] ?? null,
            'entity_ref' => $data['entity_ref'] ?? null,
            'amount' => $amount,
            'payload' => $data['payload'] ?? null,
            // The frozen chain + progress counters are the request's only state; the active stage
            // (its approver target, quorum, SoD toggle) is read from stages_snapshot[current_stage].
            'current_stage' => 1,
            'total_stages' => count($chain) ?: 1,
            'stages_snapshot' => $chain ?: null,
            'approvals_count' => 0,
            'status' => $needsApproval ? ApprovalRequest::PENDING : ApprovalRequest::AUTO_APPROVED,
            'requested_by' => $data['requested_by'] ?? null,
            'decided_at' => $needsApproval ? null : now(),
        ]);

        $this->emit($request, $needsApproval ? 'ApprovalRequested' : 'ApprovalAutoApproved');

        return $request;
    }

    public function decide(ApprovalRequest $request, bool $approve, ?User $actorUser = null, ?string $reason = null): ApprovalRequest
    {
        if ($request->status !== ApprovalRequest::PENDING) {
            throw DomainException::conflict('Approval request is not pending.');
        }
        $actor = $actorUser?->uid;
        $stage = $this->currentStage($request);

        // APR-6 (segregation of duties): the requester cannot approve their own request
        // unless THIS stage explicitly allows it (allow_requester = true, config).
        if (! ($stage['allow_requester'] ?? false) && $actor !== null && $actor === $request->requested_by) {
            throw new DomainException('SELF_APPROVAL_NOT_ALLOWED', 'The requester cannot approve their own request.', 403);
        }
        // APR-5: the approver must be authorised for the CURRENT stage (its role, or being the named user).
        $this->assertStageApprover($stage, $actorUser);
        // Hierarchy: one person fills one slot per stage — they cannot approve the same stage twice (APR-8).
        if ($approve && $actor !== null && $this->actorAlreadyApprovedStage($request, $actor)) {
            throw new DomainException('DUPLICATE_STAGE_APPROVER', 'You have already approved this stage; a distinct approver is required.', 409);
        }
        // APR-7: every action writes an immutable decision record (tagged with the stage it belongs to).
        $this->recordDecision($request, $approve ? 'APPROVE' : 'REJECT', $actor, $reason, (int) $request->current_stage);

        if (! $approve) {
            $request->update(['status' => ApprovalRequest::REJECTED, 'decided_by' => $actor, 'decision_reason' => $reason, 'decided_at' => now()]);
            $this->emit($request, 'ApprovalRejected');

            return $request->refresh();
        }

        $count = $request->approvals_count + 1;
        $stageQuorum = (int) ($stage['required_approvals'] ?? 1);

        if ($count < $stageQuorum) {
            // Stage still gathering approvals.
            $request->update(['approvals_count' => $count, 'decided_by' => $actor, 'decision_reason' => $reason]);

            return $request->refresh();
        }

        // Stage cleared. Advance to the next stage, or finalise if this was the last.
        $isLastStage = (int) $request->current_stage >= (int) $request->total_stages;
        if ($isLastStage) {
            $request->update([
                'approvals_count' => $count,
                'status' => ApprovalRequest::APPROVED,
                'decided_by' => $actor,
                'decision_reason' => $reason,
                'decided_at' => now(),
            ]);
            $this->emit($request, 'ApprovalApproved');

            return $request->refresh();
        }

        $request->update([
            'current_stage' => (int) $request->current_stage + 1, // next stage read from stages_snapshot
            'approvals_count' => 0,
            'decided_by' => $actor,
            'decision_reason' => $reason,
        ]);
        $this->emit($request->refresh(), 'ApprovalStageAdvanced');

        return $request;
    }

    /**
     * Build the ordered stage chain for a definition from its approval_stage rows. A definition with
     * no stages is a misconfiguration; we still gate it with a single open stage (any approver) rather
     * than silently auto-approving a policy that exists.
     *
     * @return list<array<string,mixed>>
     */
    private function resolveChain(ApprovalDefinition $def): array
    {
        $stages = $def->stages()->get();
        if ($stages->isEmpty()) {
            return [$this->openStage(1)];
        }

        return $stages->map(fn (ApprovalStage $s) => [
            'sequence' => (int) $s->sequence,
            'name' => $s->name,
            'approver_kind' => $s->approver_kind ?: ApprovalStage::ROLE,
            'approver_roles' => $s->approver_roles,
            'approver_user_ref' => $s->approver_user_ref,
            'approver_email' => $s->approver_email,
            'required_approvals' => (int) $s->required_approvals ?: 1,
            'allow_requester' => (bool) $s->allow_requester,
        ])->values()->all();
    }

    /**
     * Normalise a caller-supplied chain into the frozen-snapshot stage shape (the same shape resolveChain
     * produces from approval_stage rows). Sequence is assigned by order. An empty list falls back to a
     * single open stage rather than silently auto-approving.
     *
     * @param  list<array<string,mixed>>  $stages
     * @return list<array<string,mixed>>
     */
    private function normaliseStages(array $stages): array
    {
        $out = [];
        foreach (array_values($stages) as $i => $s) {
            $out[] = [
                'sequence' => $i + 1,
                'name' => $s['name'] ?? null,
                'approver_kind' => $s['approver_kind'] ?? ApprovalStage::ROLE,
                'approver_roles' => $s['approver_roles'] ?? [],
                'approver_user_ref' => $s['approver_user_ref'] ?? null,
                'approver_email' => $s['approver_email'] ?? null,
                'required_approvals' => (int) ($s['required_approvals'] ?? 1) ?: 1,
                'allow_requester' => (bool) ($s['allow_requester'] ?? false),
            ];
        }

        return $out ?: [$this->openStage(1)];
    }

    /** @return array<string,mixed> a single ROLE stage anyone with the permission may clear. */
    private function openStage(int $sequence): array
    {
        return [
            'sequence' => $sequence,
            'name' => null,
            'approver_kind' => ApprovalStage::ROLE,
            'approver_roles' => [],
            'approver_user_ref' => null,
            'approver_email' => null,
            'required_approvals' => 1,
            'allow_requester' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function currentStage(ApprovalRequest $request): array
    {
        return $this->stageAt($request, (int) $request->current_stage);
    }

    /** @return array<string,mixed> */
    private function stageAt(ApprovalRequest $request, int $sequence): array
    {
        foreach ($request->stages_snapshot ?? [] as $stage) {
            if ((int) ($stage['sequence'] ?? 0) === $sequence) {
                return $stage;
            }
        }

        // A PENDING request always has a snapshot; this only guards a malformed/legacy row.
        return $this->openStage($sequence);
    }

    /** APR-5: enforce the current stage's approver target (a role pool, or a specific named user). */
    private function assertStageApprover(array $stage, ?User $actorUser): void
    {
        if ($actorUser && $actorUser->hasRole('SUPER_ADMIN')) {
            return; // platform super-admin may always act
        }

        if (($stage['approver_kind'] ?? ApprovalStage::ROLE) === ApprovalStage::USER) {
            $uid = $stage['approver_user_ref'] ?? null;
            $email = $stage['approver_email'] ?? null;
            $matches = $actorUser && (($uid !== null && $actorUser->uid === $uid) || ($email !== null && $actorUser->email === $email));
            if (! $matches) {
                throw new DomainException('APPROVER_NOT_AUTHORIZED', 'This stage must be approved by the named approver.', 403);
            }

            return;
        }

        $roles = $stage['approver_roles'] ?? [];
        if ($roles !== [] && $roles !== null && ! ($actorUser && $actorUser->hasAnyRole($roles))) {
            throw new DomainException('APPROVER_NOT_AUTHORIZED', 'You do not hold a role permitted to act on this stage.', 403);
        }
    }

    /** Has this actor already recorded an APPROVE on the request's current stage? (distinct-approver guard) */
    private function actorAlreadyApprovedStage(ApprovalRequest $request, string $actor): bool
    {
        return DB::table('approval_decision')
            ->where('request_id', $request->request_id)
            ->where('stage_sequence', (int) $request->current_stage)
            ->where('decision', 'APPROVE')
            ->where('actor_user_id', $actor)
            ->exists();
    }

    /** APR-7: immutable per-action decision audit (tagged with the stage). */
    private function recordDecision(ApprovalRequest $request, string $decision, ?string $actor, ?string $comment, ?int $stageSequence): void
    {
        DB::table('approval_decision')->insert([
            'decision_id' => Id::make('appdec'),
            'request_id' => $request->request_id,
            'operator_code' => $request->operator_code,
            'decision' => $decision,
            'stage_sequence' => $stageSequence,
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
            payload: ['requestId' => $request->request_id, 'entityType' => $request->entity_type, 'status' => $request->status, 'currentStage' => (int) $request->current_stage],
            aggregateType: 'ApprovalRequest',
            aggregateId: $request->request_id,
        ));
    }
}
