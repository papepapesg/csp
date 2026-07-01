<?php

namespace Modules\Billing\Adjustments\Services;

use App\Foundation\Approvals\ApprovalDefinition;
use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Adjustments\Models\AdjustmentApprovalStep;
use Modules\Billing\Adjustments\Models\AdjustmentReasonCode;
use Modules\Billing\Adjustments\Models\AdjustmentRequest;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Invoicing\Models\InvoiceLine;
use Modules\Billing\Invoicing\Services\InvoiceService;
use Modules\Billing\Payments\Models\NoteApplication;
use Modules\Billing\Payments\Services\NoteApplicationService;

/**
 * BIL-02-ADJ-01 invoice adjustments — the governed proposal → approval →
 * application pipeline for credit and debit notes. Nobody edits an invoice in
 * place: an agent PROPOSES an adjustment (scoped FULL / LINE / AMOUNT, with a
 * mandatory operator reason code); on approval GEN-01 issues the CREDIT_NOTE /
 * DEBIT_NOTE invoice and BIL-01-CN-01 applies it.
 *
 * Approval is definition-first on the EM-CFG-04 engine, in two layers:
 *
 *  1. ROUTING — the `rules.billing.adjustment-approval` decision table
 *     (operator-overridable) receives the proposal facts and names the
 *     pre-authored approval PROCESS to use: the action of an ADJUSTMENT
 *     approval_definition (PROCESS_AUTO / PROCESS_SINGLE / PROCESS_DUAL, or any
 *     operator-authored chain such as a multi-stage executive sign-off). The
 *     decision and the rule that made it are PINNED on the proposal.
 *
 *  2. GATING — every non-blocked proposal opens an engine gate against that
 *     process. The engine owns the chain (stages, quorum, distinct approvers,
 *     SoD); AUTO is a real process too, recorded by the engine as
 *     AUTO_APPROVED. There is no local approval path.
 *
 * Operator guard-rails (max per request / per customer-period, and the
 * auto-approve threshold the routing fallback uses) live in the config bag of
 * the BASE ADJUSTMENT process row (action = null) — config on the process row,
 * not a separate table. Route permission (`adjustment.approve`) gates WHO may
 * act; adjustment_approval_step keeps the human-readable audit timeline
 * (including the non-approval events: limit override, revision, auto-approve).
 */
class AdjustmentService
{
    public const APPROVAL_RULE_SET = 'rules.billing.adjustment-approval';

    public function __construct(
        private readonly EventBus $events,
        private readonly InvoiceService $invoices,
        private readonly NoteApplicationService $noteApplication,
        private readonly RuleEngine $rules,
        private readonly ApprovalService $approvals,
    ) {}

    /**
     * @param  array<string,mixed>  $data
     */
    public function propose(array $data, ?string $proposedBy = null): AdjustmentRequest
    {
        return DB::transaction(function () use ($data, $proposedBy) {
            $operator = $data['operator_code'] ?? Context::operatorCode();
            $direction = $data['direction'];
            $scope = $data['scope'];

            // Reason code: mandatory, from the operator catalog, direction-compatible.
            $reason = AdjustmentReasonCode::activeByCode($operator, (string) $data['reason_code']);
            if (! $reason) {
                throw DomainException::ruleRejected('UNKNOWN_REASON_CODE', "Reason code '{$data['reason_code']}' is not in the operator catalog.");
            }
            if (! $reason->allows($direction)) {
                throw DomainException::ruleRejected('REASON_DIRECTION_MISMATCH', "Reason '{$reason->code}' does not justify {$direction} adjustments.");
            }

            // Parent invoice: required for FULL/LINE scope and for all POSTPAID
            // debit notes (a debit is always tied to a source invoice).
            $parent = null;
            $billingMode = $data['billing_mode'] ?? AdjustmentRequest::POSTPAID;
            if (! empty($data['parent_invoice_id'])) {
                $parent = $this->adjustableParent((string) $data['parent_invoice_id']);
                $billingMode = $parent->billing_mode ?? $billingMode;
            } elseif (in_array($scope, [AdjustmentRequest::FULL, AdjustmentRequest::LINE], true)) {
                throw DomainException::ruleRejected('PARENT_INVOICE_REQUIRED', "Scope {$scope} requires a parent invoice.");
            } elseif ($direction === AdjustmentRequest::DEBIT && $billingMode !== AdjustmentRequest::PREPAID) {
                throw DomainException::ruleRejected('PARENT_INVOICE_REQUIRED', 'A postpaid debit note is always tied to a source invoice.');
            }

            // Scope → amount determination.
            [$amount, $lineRef, $categoryCode] = $this->determineAmount($scope, $data, $parent);
            $currency = $parent?->currency ?? ($data['currency'] ?? 'KES');

            // Operator guard-rails — a breach parks the proposal until /override-limit lifts it.
            $limitBreach = $this->checkLimits($operator, $data['customer_id'] ?? $parent?->customer_id, $amount);

            // Approval routing: the rules engine names the pre-authored process
            // (operator-overridable decision table; config-derived fallback when none is deployed).
            $routing = $this->rules->evaluate(self::APPROVAL_RULE_SET, [
                'operatorCode' => $operator,
                'direction' => $direction,
                'scope' => $scope,
                'amount' => $amount,
                'currency' => $currency,
                'reasonCode' => $reason->code,
                'billingMode' => $billingMode,
                'customerId' => $data['customer_id'] ?? $parent?->customer_id,
                'limitBreached' => $limitBreach !== null,
            ]);
            $stepsRequired = max(0, (int) ($routing['stepsRequired'] ?? 1));
            // Legacy tables that answer only stepsRequired are mapped onto the standard tiers.
            $process = (string) ($routing['approvalProcess'] ?? $this->processForSteps($stepsRequired));

            $adjustment = AdjustmentRequest::query()->create([
                'operator_code' => $operator,
                'customer_id' => $data['customer_id'] ?? $parent?->customer_id,
                'account_id' => $data['account_id'] ?? $parent?->account_id,
                'subscription_id' => $data['subscription_id'] ?? $parent?->subscription_id,
                'parent_invoice_id' => $parent?->invoice_id,
                'target_wallet_ref' => $data['target_wallet_ref'] ?? null,
                'billing_mode' => $billingMode,
                'direction' => $direction,
                'scope' => $scope,
                'line_ref' => $lineRef,
                'service_category_code' => $categoryCode,
                'amount' => $amount,
                'currency' => $currency,
                'reason_code' => $reason->code,
                'gl_code' => $reason->glFor($direction),
                'justification' => $data['justification'] ?? null,
                'status' => $limitBreach ? AdjustmentRequest::PROPOSED : AdjustmentRequest::PENDING_APPROVAL,
                'failure_reason' => $limitBreach,
                'required_approvals' => $stepsRequired,
                'approval_rule_id' => $routing['ruleId'] ?? null,
                'approval_process' => $process,
                'proposed_by' => $proposedBy,
            ]);

            $this->emit($adjustment, BillingEvents::ADJUSTMENT_PROPOSED);

            // A limit-blocked proposal stays PROPOSED; its gate opens when /override-limit lifts the block.
            if ($limitBreach !== null) {
                return $adjustment;
            }

            return $this->openGateAndMaybeApply($adjustment, $routing);
        });
    }

    public function approve(AdjustmentRequest $adjustment, ?User $actor = null, ?string $comment = null): AdjustmentRequest
    {
        return DB::transaction(function () use ($adjustment, $actor, $comment) {
            $this->assertOpenForDecision($adjustment);
            if ($adjustment->isLimitBlocked()) {
                throw DomainException::ruleRejected('LIMIT_OVERRIDE_REQUIRED', 'This proposal breaches the operator adjustment limits; override the limit first.', nextAction: 'OVERRIDE_LIMIT');
            }

            // The EM-CFG-04 gate owns the chain: this records one approval on the current stage and
            // tells us whether the process is now cleared. A second approval by the SAME person is
            // refused by the engine (DUPLICATE_STAGE_APPROVER) — that is the dual-control guarantee.
            $gate = $this->approvalGate($adjustment) ?? $this->openApprovalGate($adjustment);
            $gate = $this->approvals->decide($gate, true, $actor, $comment);

            $adjustment->logStep(AdjustmentApprovalStep::APPROVED, $this->actorRef($actor), $comment);

            if ($gate->status !== ApprovalRequest::APPROVED) {
                return $adjustment->refresh(); // the chain still needs further approvals
            }

            return $this->approveAndApply($adjustment);
        });
    }

    public function reject(AdjustmentRequest $adjustment, ?User $actor = null, ?string $comment = null): AdjustmentRequest
    {
        return DB::transaction(function () use ($adjustment, $actor, $comment) {
            $this->assertOpenForDecision($adjustment);
            // A reject on the gate fails the whole chain; a limit-blocked proposal has no gate yet, so we
            // simply close it locally.
            if ($gate = $this->approvalGate($adjustment)) {
                $this->approvals->decide($gate, false, $actor, $comment);
            }
            $adjustment->logStep(AdjustmentApprovalStep::REJECTED, $this->actorRef($actor), $comment);
            $adjustment->update(['status' => AdjustmentRequest::REJECTED]);
            $this->emit($adjustment, BillingEvents::ADJUSTMENT_REJECTED);

            return $adjustment;
        });
    }

    public function requestRevision(AdjustmentRequest $adjustment, ?string $decidedBy = null, ?string $comment = null): AdjustmentRequest
    {
        $this->assertOpenForDecision($adjustment);
        $adjustment->logStep(AdjustmentApprovalStep::REVISION_REQUESTED, $decidedBy, $comment);
        $adjustment->update(['status' => AdjustmentRequest::PROPOSED]);

        return $adjustment;
    }

    /** The proposer withdraws — only before approval. */
    public function cancel(AdjustmentRequest $adjustment): AdjustmentRequest
    {
        $this->assertOpenForDecision($adjustment);
        $adjustment->update(['status' => AdjustmentRequest::CANCELLED_BY_PROPOSER]);

        return $adjustment;
    }

    /** Approver lifts a limit breach so the proposal may enter approval. */
    public function overrideLimit(AdjustmentRequest $adjustment, ?string $decidedBy = null): AdjustmentRequest
    {
        if ($adjustment->failure_reason !== AdjustmentRequest::LIMIT_EXCEEDED) {
            throw DomainException::conflict('This proposal has no limit breach to override.');
        }

        return DB::transaction(function () use ($adjustment, $decidedBy) {
            $adjustment->update([
                'limit_overridden' => true,
                'failure_reason' => null,
                'status' => AdjustmentRequest::PENDING_APPROVAL,
            ]);
            $adjustment->logStep(AdjustmentApprovalStep::LIMIT_OVERRIDDEN, $decidedBy);

            // The breach is lifted: enter approval through the one engine entry point (it was withheld
            // while blocked).
            if (! $this->approvalGate($adjustment)) {
                return $this->openGateAndMaybeApply($adjustment);
            }

            return $adjustment;
        });
    }

    /** Re-attempt a failed application (e.g. PREPAID debit after a top-up). */
    public function retryApplication(AdjustmentRequest $adjustment): AdjustmentRequest
    {
        if ($adjustment->status !== AdjustmentRequest::APPLICATION_FAILED) {
            throw DomainException::conflict('Only a failed application can be retried.');
        }

        return DB::transaction(function () use ($adjustment) {
            $note = Invoice::query()->findOrFail($adjustment->note_invoice_id);

            return $this->applyNote($adjustment, $note);
        });
    }

    // ── Approval gate (EM-CFG-04) ────────────────────────────────────────────

    /**
     * Open the gate for the process pinned on the proposal and act on the engine's answer: an
     * auto-approving process comes back approved BY THE ENGINE — we mirror that single decision onto
     * the audit timeline and apply. A real chain comes back PENDING, so we wait for approve().
     * This is the only place a proposal enters approval — AUTO included, no exceptions.
     *
     * @param  array<string,mixed>  $routing
     */
    private function openGateAndMaybeApply(AdjustmentRequest $adjustment, array $routing = []): AdjustmentRequest
    {
        $gate = $this->openApprovalGate($adjustment);

        if (in_array($gate->status, [ApprovalRequest::AUTO_APPROVED, ApprovalRequest::APPROVED], true)) {
            $adjustment->logStep(
                AdjustmentApprovalStep::APPROVED,
                AdjustmentApprovalStep::SYSTEM_AUTO,
                'Auto-approved by the '.($adjustment->approval_process ?: 'approval').' process'
                    .(isset($routing['ruleId']) ? " (rule {$routing['ruleId']})" : ''),
            );

            return $this->approveAndApply($adjustment);
        }

        return $adjustment->refresh();
    }

    /**
     * Raise the gate against the PRE-AUTHORED ADJUSTMENT process the rules engine selected (the
     * approval_definition action pinned on the proposal). No inline stages — the chain (quorum,
     * roles, SoD) comes from that definition, consistent with every other approval flow.
     * requested_by is null so SoD never blocks a legitimate approver; the stage's distinct-approver
     * quorum is what stops one person self-clearing a multi-approval gate.
     */
    private function openApprovalGate(AdjustmentRequest $adjustment): ApprovalRequest
    {
        return $this->approvals->request([
            'operator_code' => $adjustment->operator_code,
            'entity_type' => AdjustmentRequest::ENTITY_TYPE,
            'action' => $adjustment->approval_process ?: AdjustmentRequest::PROCESS_SINGLE,
            'entity_ref' => $adjustment->adjustment_id,
            'amount' => (float) $adjustment->amount,
            'requested_by' => null,
        ]);
    }

    /** The open EM-CFG-04 gate for this adjustment, if one has been raised. */
    private function approvalGate(AdjustmentRequest $adjustment): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('entity_type', AdjustmentRequest::ENTITY_TYPE)
            ->where('entity_ref', $adjustment->adjustment_id)
            ->where('status', ApprovalRequest::PENDING)
            ->latest('created_at')->first();
    }

    /** Map a legacy stepsRequired count to a pre-authored process tier. */
    private function processForSteps(int $steps): string
    {
        return match (true) {
            $steps <= 0 => AdjustmentRequest::PROCESS_AUTO,
            $steps === 1 => AdjustmentRequest::PROCESS_SINGLE,
            default => AdjustmentRequest::PROCESS_DUAL,
        };
    }

    // ── Application (GEN-01 note → CN-01 money movement) ─────────────────────

    /** APPROVED → issue the note document (GEN-01) → apply it (CN-01). */
    private function approveAndApply(AdjustmentRequest $adjustment): AdjustmentRequest
    {
        $adjustment->update(['status' => AdjustmentRequest::APPROVED]);
        $this->emit($adjustment, BillingEvents::ADJUSTMENT_APPROVED);

        $noteType = $adjustment->direction === AdjustmentRequest::CREDIT ? Invoice::CREDIT_NOTE : Invoice::DEBIT_NOTE;
        $note = $this->invoices->issueNote($noteType, [
            'operator_code' => $adjustment->operator_code,
            'account_id' => $adjustment->account_id,
            'customer_id' => $adjustment->customer_id,
            'subscription_id' => $adjustment->subscription_id,
            'original_invoice_id' => $adjustment->parent_invoice_id,
            'target_wallet_ref' => $adjustment->target_wallet_ref,
            'adjustment_request_id' => $adjustment->adjustment_id,
            'billing_mode' => $adjustment->billing_mode,
            'amount' => (float) $adjustment->amount,
            'currency' => $adjustment->currency,
            'reason_code' => $adjustment->reason_code,
            'description' => $adjustment->justification ?? "{$adjustment->reason_code} adjustment",
        ]);
        $adjustment->update(['note_invoice_id' => $note->invoice_id]);

        return $this->applyNote($adjustment, $note);
    }

    private function applyNote(AdjustmentRequest $adjustment, Invoice $note): AdjustmentRequest
    {
        $result = $this->noteApplication->apply($note, $adjustment);

        $adjustment->update($result['status'] === NoteApplication::APPLIED
            ? ['status' => AdjustmentRequest::APPLIED, 'failure_reason' => null, 'applied_at' => now()]
            : ['status' => AdjustmentRequest::APPLICATION_FAILED, 'failure_reason' => $result['failure_reason']]);

        return $adjustment->refresh();
    }

    // ── Proposal validation ──────────────────────────────────────────────────

    /** The parent must exist and be adjustable: not a signed tax invoice, not a note, not cancelled. */
    private function adjustableParent(string $invoiceId): Invoice
    {
        $parent = Invoice::query()->find($invoiceId);
        if (! $parent) {
            throw DomainException::notFound('Parent invoice not found.');
        }
        if ($parent->type === Invoice::TAX) {
            throw DomainException::ruleRejected('CANNOT_ADJUST_TAX_INVOICE', 'A signed tax invoice cannot be adjusted; adjust the commercial invoice.');
        }
        if (in_array($parent->type, [Invoice::CREDIT_NOTE, Invoice::DEBIT_NOTE], true)) {
            throw DomainException::ruleRejected('CANNOT_ADJUST_NOTE', 'A credit/debit note cannot itself be adjusted.');
        }
        if ($parent->status === Invoice::VOID) {
            throw DomainException::ruleRejected('INVOICE_NOT_APPLIABLE', 'A cancelled invoice cannot be adjusted.');
        }

        return $parent;
    }

    /**
     * Scope rules (R-ADJ-01-P-3): FULL takes the parent's total; LINE takes the
     * line's amount (or an explicit partial capped by it) and inherits the line's
     * classification; AMOUNT is free-form and must carry its own classification.
     *
     * @return array{0: float, 1: ?string, 2: ?string}
     */
    private function determineAmount(string $scope, array $data, ?Invoice $parent): array
    {
        if ($scope === AdjustmentRequest::FULL) {
            return [round((float) $parent->total_amount, 2), null, $data['service_category_code'] ?? null];
        }

        if ($scope === AdjustmentRequest::LINE) {
            $line = InvoiceLine::query()->whereKey($data['line_ref'] ?? null)
                ->where('invoice_id', $parent->invoice_id)->first();
            if (! $line) {
                throw DomainException::ruleRejected('UNKNOWN_INVOICE_LINE', 'line_ref must identify a line of the parent invoice.');
            }
            $lineTotal = round((float) $line->subtotal + (float) $line->tax_amount, 2);
            $amount = isset($data['amount']) ? round((float) $data['amount'], 2) : $lineTotal;
            if ($amount <= 0 || $amount > $lineTotal) {
                throw DomainException::ruleRejected('LINE_AMOUNT_OUT_OF_RANGE', "A LINE adjustment must be between 0 and the line total ({$lineTotal}).");
            }

            return [$amount, (string) $line->getKey(), $data['service_category_code'] ?? $line->service_ref];
        }

        // AMOUNT scope: free-form, mandatory finance classification.
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw DomainException::ruleRejected('INVALID_AMOUNT', 'An AMOUNT adjustment requires a positive amount.');
        }
        if (empty($data['service_category_code'])) {
            throw DomainException::ruleRejected('SERVICE_CATEGORY_REQUIRED', 'An AMOUNT adjustment must carry a service_category_code.');
        }

        return [$amount, null, $data['service_category_code']];
    }

    /**
     * Returns the breach code, or null when within the operator's limits. Limits live in the config
     * bag of the BASE ADJUSTMENT process row (approval_definition, action = null) — EM-CFG-04
     * "config on the process row", not a separate per-operator table.
     */
    private function checkLimits(string $operator, ?string $customerId, float $amount): ?string
    {
        $config = ApprovalDefinition::query()
            ->where('operator_code', $operator)
            ->where('entity_type', AdjustmentRequest::ENTITY_TYPE)
            ->whereNull('action')
            ->first()?->config;
        if (! $config) {
            return null;
        }

        if (($config['max_per_request'] ?? null) !== null && $amount > (float) $config['max_per_request']) {
            return AdjustmentRequest::LIMIT_EXCEEDED;
        }

        if (($config['max_per_customer_period'] ?? null) !== null && $customerId) {
            $windowTotal = (float) AdjustmentRequest::query()
                ->where('operator_code', $operator)
                ->where('customer_id', $customerId)
                ->whereNotIn('status', [AdjustmentRequest::REJECTED, AdjustmentRequest::CANCELLED_BY_PROPOSER])
                ->where('created_at', '>=', now()->subDays((int) ($config['period_days'] ?? 30)))
                ->sum('amount');
            if ($windowTotal + $amount > (float) $config['max_per_customer_period']) {
                return AdjustmentRequest::LIMIT_EXCEEDED;
            }
        }

        return null;
    }

    // ── Shared helpers ───────────────────────────────────────────────────────

    private function assertOpenForDecision(AdjustmentRequest $adjustment): void
    {
        if (! in_array($adjustment->status, AdjustmentRequest::OPEN_STATUSES, true)) {
            throw DomainException::conflict("Adjustment is {$adjustment->status}; no further decisions are possible.");
        }
    }

    /** Stable string handle for the deciding user, for the human-readable adjustment audit. */
    private function actorRef(?User $actor): ?string
    {
        return $actor?->uid ?? $actor?->email;
    }

    private function emit(AdjustmentRequest $adjustment, string $type): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: BillingEvents::TOPIC,
            payload: [
                'adjustmentId' => $adjustment->adjustment_id,
                'customerId' => $adjustment->customer_id,
                'parentInvoiceId' => $adjustment->parent_invoice_id,
                'direction' => $adjustment->direction,
                'scope' => $adjustment->scope,
                'amount' => (string) $adjustment->amount,
                'currency' => $adjustment->currency,
                'reasonCode' => $adjustment->reason_code,
                'status' => $adjustment->status,
            ],
            aggregateType: 'AdjustmentRequest',
            aggregateId: $adjustment->adjustment_id,
        ));
    }
}
