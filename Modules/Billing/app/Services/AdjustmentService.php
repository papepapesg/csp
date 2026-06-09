<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\AdjustmentReasonCode;
use Modules\Billing\Models\AdjustmentRequest;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;

/**
 * BIL-02-ADJ-01 invoice adjustments — the governed proposal → approval →
 * application pipeline for credit and debit notes. Nobody edits an invoice in
 * place: an agent PROPOSES an adjustment (scoped FULL / LINE / AMOUNT, with a
 * mandatory operator reason code, guarded by adjustment_limits_config); the
 * operator's approval policy decides (zero-step auto-approve, threshold, or
 * one or more approval steps, each audited); on approval GEN-01 issues the
 * CREDIT_NOTE / DEBIT_NOTE invoice and BIL-01-CN-01 applies it.
 */
class AdjustmentService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly InvoiceService $invoices,
        private readonly NoteApplicationService $noteApplication,
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
            if ($reason->direction !== 'ANY' && $reason->direction !== $direction) {
                throw DomainException::ruleRejected('REASON_DIRECTION_MISMATCH', "Reason '{$reason->code}' does not justify {$direction} adjustments.");
            }

            // Parent invoice: required for FULL/LINE scope and for all POSTPAID
            // debit notes (a debit is always tied to a source invoice).
            $parent = null;
            $billingMode = $data['billing_mode'] ?? 'POSTPAID';
            if (! empty($data['parent_invoice_id'])) {
                $parent = Invoice::query()->find($data['parent_invoice_id']);
                if (! $parent) {
                    throw DomainException::notFound('Parent invoice not found.');
                }
                if ($parent->type === 'TAX') {
                    throw DomainException::ruleRejected('CANNOT_ADJUST_TAX_INVOICE', 'A signed tax invoice cannot be adjusted; adjust the commercial invoice.');
                }
                if (in_array($parent->type, [Invoice::CREDIT_NOTE, Invoice::DEBIT_NOTE], true)) {
                    throw DomainException::ruleRejected('CANNOT_ADJUST_NOTE', 'A credit/debit note cannot itself be adjusted.');
                }
                if ($parent->status === Invoice::VOID) {
                    throw DomainException::ruleRejected('INVOICE_NOT_APPLIABLE', 'A cancelled invoice cannot be adjusted.');
                }
                $billingMode = $parent->billing_mode ?? $billingMode;
            } elseif (in_array($scope, ['FULL', 'LINE'], true)) {
                throw DomainException::ruleRejected('PARENT_INVOICE_REQUIRED', "Scope {$scope} requires a parent invoice.");
            } elseif ($direction === AdjustmentRequest::DEBIT && $billingMode !== 'PREPAID') {
                throw DomainException::ruleRejected('PARENT_INVOICE_REQUIRED', 'A postpaid debit note is always tied to a source invoice.');
            }

            // Scope → amount determination.
            [$amount, $lineRef, $categoryCode] = $this->determineAmount($scope, $data, $parent);
            $currency = $parent?->currency ?? ($data['currency'] ?? 'KES');

            // Limits (adjustment_limits_config) — flagged on the proposal; an
            // explicit /override-limit (approver permission) lifts the block.
            $limitBreach = $this->checkLimits($operator, $data['customer_id'] ?? $parent?->customer_id, $amount);

            $config = DB::table('adjustment_limits_config')->where('operator_code', $operator)->first();
            $stepsRequired = (int) ($config->approval_steps_required ?? 1);
            $autoUnder = $config->auto_approve_under ?? null;
            $autoApprove = $limitBreach === null
                && ($stepsRequired === 0 || ($autoUnder !== null && $amount < (float) $autoUnder));

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
                'justification' => $data['justification'] ?? null,
                'status' => $limitBreach ? AdjustmentRequest::PROPOSED : AdjustmentRequest::PENDING_APPROVAL,
                'failure_reason' => $limitBreach,
                'proposed_by' => $proposedBy,
            ]);

            $this->emit($adjustment, BillingEvents::ADJUSTMENT_PROPOSED);

            if ($autoApprove) {
                // Zero-step / under-threshold policy: approve + apply in-line, with
                // an audit step recording the automatic decision.
                $adjustment->approvalSteps()->create([
                    'step_no' => 1, 'decision' => 'APPROVED', 'decided_by' => 'SYSTEM:AUTO_APPROVE',
                    'comment' => $stepsRequired === 0 ? 'Zero-step approval policy' : "Under auto-approve threshold {$autoUnder}",
                    'decided_at' => now(),
                ]);

                return $this->approveAndApply($adjustment);
            }

            return $adjustment;
        });
    }

    public function approve(AdjustmentRequest $adjustment, ?string $decidedBy = null, ?string $comment = null): AdjustmentRequest
    {
        return DB::transaction(function () use ($adjustment, $decidedBy, $comment) {
            $this->assertOpenForDecision($adjustment);
            if ($adjustment->failure_reason === 'ADJUSTMENT_LIMIT_EXCEEDED' && ! $adjustment->limit_overridden) {
                throw DomainException::ruleRejected('LIMIT_OVERRIDE_REQUIRED', 'This proposal breaches the operator adjustment limits; override the limit first.', nextAction: 'OVERRIDE_LIMIT');
            }

            $stepNo = $adjustment->approvalSteps()->count() + 1;
            $adjustment->approvalSteps()->create([
                'step_no' => $stepNo, 'decision' => 'APPROVED', 'decided_by' => $decidedBy,
                'comment' => $comment, 'decided_at' => now(),
            ]);

            $config = DB::table('adjustment_limits_config')->where('operator_code', $adjustment->operator_code)->first();
            $required = max(1, (int) ($config->approval_steps_required ?? 1));
            $approvals = $adjustment->approvalSteps()->where('decision', 'APPROVED')->count();

            if ($approvals < $required) {
                return $adjustment->refresh(); // multi-step: wait for the next approver
            }

            return $this->approveAndApply($adjustment);
        });
    }

    public function reject(AdjustmentRequest $adjustment, ?string $decidedBy = null, ?string $comment = null): AdjustmentRequest
    {
        $this->assertOpenForDecision($adjustment);
        $adjustment->approvalSteps()->create([
            'step_no' => $adjustment->approvalSteps()->count() + 1,
            'decision' => 'REJECTED', 'decided_by' => $decidedBy, 'comment' => $comment, 'decided_at' => now(),
        ]);
        $adjustment->update(['status' => AdjustmentRequest::REJECTED]);
        $this->emit($adjustment, BillingEvents::ADJUSTMENT_REJECTED);

        return $adjustment;
    }

    public function requestRevision(AdjustmentRequest $adjustment, ?string $decidedBy = null, ?string $comment = null): AdjustmentRequest
    {
        $this->assertOpenForDecision($adjustment);
        $adjustment->approvalSteps()->create([
            'step_no' => $adjustment->approvalSteps()->count() + 1,
            'decision' => 'REVISION_REQUESTED', 'decided_by' => $decidedBy, 'comment' => $comment, 'decided_at' => now(),
        ]);
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
        if ($adjustment->failure_reason !== 'ADJUSTMENT_LIMIT_EXCEEDED') {
            throw DomainException::conflict('This proposal has no limit breach to override.');
        }
        $adjustment->update([
            'limit_overridden' => true,
            'failure_reason' => null,
            'status' => AdjustmentRequest::PENDING_APPROVAL,
        ]);
        $adjustment->approvalSteps()->create([
            'step_no' => $adjustment->approvalSteps()->count() + 1,
            'decision' => 'LIMIT_OVERRIDDEN', 'decided_by' => $decidedBy, 'decided_at' => now(),
        ]);

        return $adjustment;
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

        $adjustment->update($result['status'] === 'APPLIED'
            ? ['status' => AdjustmentRequest::APPLIED, 'failure_reason' => null, 'applied_at' => now()]
            : ['status' => AdjustmentRequest::APPLICATION_FAILED, 'failure_reason' => $result['failure_reason']]);

        return $adjustment->refresh();
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
        if ($scope === 'FULL') {
            return [round((float) $parent->total_amount, 2), null, $data['service_category_code'] ?? null];
        }

        if ($scope === 'LINE') {
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

    /** Returns the breach code, or null when within the operator's limits. */
    private function checkLimits(string $operator, ?string $customerId, float $amount): ?string
    {
        $config = DB::table('adjustment_limits_config')->where('operator_code', $operator)->first();
        if (! $config) {
            return null;
        }

        if ($config->max_per_request !== null && $amount > (float) $config->max_per_request) {
            return 'ADJUSTMENT_LIMIT_EXCEEDED';
        }

        if ($config->max_per_customer_period !== null && $customerId) {
            $windowTotal = (float) AdjustmentRequest::query()
                ->where('operator_code', $operator)
                ->where('customer_id', $customerId)
                ->whereNotIn('status', [AdjustmentRequest::REJECTED, AdjustmentRequest::CANCELLED_BY_PROPOSER])
                ->where('created_at', '>=', now()->subDays((int) $config->period_days))
                ->sum('amount');
            if ($windowTotal + $amount > (float) $config->max_per_customer_period) {
                return 'ADJUSTMENT_LIMIT_EXCEEDED';
            }
        }

        return null;
    }

    private function assertOpenForDecision(AdjustmentRequest $adjustment): void
    {
        if (! in_array($adjustment->status, [AdjustmentRequest::PROPOSED, AdjustmentRequest::PENDING_APPROVAL], true)) {
            throw DomainException::conflict("Adjustment is {$adjustment->status}; no further decisions are possible.");
        }
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
