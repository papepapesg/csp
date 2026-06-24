<?php

namespace Modules\Billing\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\Invoice;

/**
 * BIL-02-GEN-01 rule group R — bulk reversal. When a whole batch is billed with
 * a systemic error (wrong tax rate, wrong package price, wrong grouping), an
 * operator cancels (and optionally re-issues) them as one auditable, dual-
 * approved operation. Never triggered by a system event (R-GEN-01-R-1).
 *
 * Flow: propose(scope) → preview → approve(FINANCE_HEAD) → execute per-invoice
 * (cancel + InvoiceCancelled, protected states rejected). Each cancellation is
 * its own transaction; partial failures are visible per invoice (R-GEN-01-R-3/5).
 *
 * The dual-control gate runs on the EM-CFG-04 engine (the platform-wide approval
 * mechanism): propose() raises a BULK_REVERSAL request with the proposer as
 * requester, and the engine's separation-of-duties rule — not a hand-rolled
 * check — refuses an approval/rejection by that same person (surfaced as the
 * existing DUAL_CONTROL_REQUIRED code). Route permission still gates who may act.
 */
class BulkReversalService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ApprovalService $approvals,
    ) {}

    /** Invoices a scope would affect, split into eligible vs protected (R-GEN-01-R-4). */
    public function preview(array $scope, ?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $matched = $this->scopeQuery($scope, $operator)->get();

        $protected = $matched->filter(fn (Invoice $i) => $this->protectedReason($i) !== null)
            ->map(fn (Invoice $i) => ['invoice_id' => $i->invoice_id, 'reason' => $this->protectedReason($i)]);
        $eligible = $matched->reject(fn (Invoice $i) => $this->protectedReason($i) !== null);

        return [
            'in_scope' => $matched->count(),
            'eligible' => $eligible->count(),
            'total_amount' => round((float) $eligible->sum('total_amount'), 2),
            'protected' => $protected->values(),
        ];
    }

    /** Create the PENDING_APPROVAL batch (BILLING_ADMIN proposes, R-GEN-01-R-1/2). */
    public function propose(array $scope, ?string $proposedBy, bool $reIssue = false, ?string $notes = null): string
    {
        $operator = $scope['operator_code'] ?? Context::operatorCode();
        $preview = $this->preview($scope, $operator);
        $batchId = Id::make('brb');

        DB::table('bulk_reversal_batch')->insert([
            'batch_id' => $batchId,
            'operator_code' => $operator,
            'invoice_type' => $scope['invoice_type'] ?? null,
            'date_from' => $scope['date_from'] ?? null,
            'date_to' => $scope['date_to'] ?? null,
            'filters' => isset($scope['filters']) ? json_encode($scope['filters']) : null,
            're_issue' => $reIssue,
            'status' => 'PENDING_APPROVAL',
            'invoices_in_scope' => $preview['eligible'],
            'proposed_by' => $proposedBy,
            'notes' => $notes,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Open the EM-CFG-04 dual-control gate with the proposer as requester (SoD anchor).
        $this->openReversalGate($batchId, $operator, $proposedBy);

        return $batchId;
    }

    public function reject(string $batchId, ?User $by): void
    {
        $this->assertStatus($batchId, 'PENDING_APPROVAL');
        $this->decideGate($batchId, false, $by); // a rejection is a control decision too: requester ≠ decider
        DB::table('bulk_reversal_batch')->where('batch_id', $batchId)
            ->update(['status' => 'REJECTED', 'approved_by' => $this->actorRef($by), 'updated_at' => now()]);
    }

    /**
     * FINANCE_HEAD approves and the batch executes per invoice (R-GEN-01-R-1/3).
     * Dual control (engine-enforced): the approver must differ from the proposer.
     */
    public function approveAndExecute(string $batchId, ?User $approver): array
    {
        $batch = $this->assertStatus($batchId, 'PENDING_APPROVAL');
        $this->decideGate($batchId, true, $approver); // engine enforces requester ≠ approver

        DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->update([
            'status' => 'IN_PROGRESS', 'approved_by' => $this->actorRef($approver), 'started_at' => now(), 'updated_at' => now(),
        ]);

        $scope = ['operator_code' => $batch->operator_code, 'invoice_type' => $batch->invoice_type,
            'date_from' => $batch->date_from, 'date_to' => $batch->date_to,
            'filters' => $batch->filters ? json_decode($batch->filters, true) : null];

        $cancelled = $failed = $reIssued = 0;
        foreach ($this->scopeQuery($scope, $batch->operator_code)->get() as $invoice) {
            if ($this->protectedReason($invoice) !== null) {
                continue; // protected invoices are out of scope at execute time too
            }
            try {
                DB::transaction(function () use ($invoice, $batchId) {
                    $invoice->update([
                        'status' => Invoice::VOID,
                        'cancel_reason_code' => 'BULK_REVERSAL',
                        'cancel_batch_id' => $batchId,
                        'amount_due' => 0,
                    ]);
                    // R-GEN-01-R-3: BIL-01 reverses outstanding, BIL-04 stops dunning,
                    // DD_NOT-01 notifies — all via the cancellation event.
                    $this->events->publish(new DomainEvent(
                        type: BillingEvents::INVOICE_CANCELLED,
                        topic: BillingEvents::TOPIC,
                        payload: ['invoiceId' => $invoice->invoice_id, 'accountId' => $invoice->account_id, 'cancelBatchId' => $batchId, 'reasonCode' => 'BULK_REVERSAL'],
                        aggregateType: 'Invoice',
                        aggregateId: $invoice->invoice_id,
                    ));
                });
                $cancelled++;
                // Re-issue (R-GEN-01-R-3 re_issue=true) is left to the owning
                // generator with fresh inputs; tracked but not auto-run here.
            } catch (\Throwable) {
                $failed++;
            }
        }

        DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->update([
            'status' => $failed > 0 ? 'PARTIALLY_FAILED' : 'COMPLETED',
            'invoices_cancelled' => $cancelled,
            'invoices_re_issued' => $reIssued,
            'invoices_failed' => $failed,
            'completed_at' => now(), 'updated_at' => now(),
        ]);

        $this->events->publish(new DomainEvent(
            type: BillingEvents::BULK_REVERSAL_COMPLETED,
            topic: BillingEvents::TOPIC,
            payload: ['batchId' => $batchId, 'cancelled' => $cancelled, 'failed' => $failed],
            aggregateType: 'BulkReversalBatch',
            aggregateId: $batchId,
        ));

        return ['batch_id' => $batchId, 'cancelled' => $cancelled, 'failed' => $failed];
    }

    /** Raise the single-stage dual-control gate; roles are open (route permission gates WHO), SoD on. */
    private function openReversalGate(string $batchId, string $operator, ?string $requestedBy): ApprovalRequest
    {
        return $this->approvals->request([
            'operator_code' => $operator,
            'entity_type' => 'BULK_REVERSAL',
            'entity_ref' => $batchId,
            'requested_by' => $requestedBy,
            'stages' => [[
                'name' => 'Bulk reversal approval',
                'approver_kind' => 'ROLE',
                'approver_roles' => [],
                'required_approvals' => 1,
                'allow_requester' => false,
            ]],
        ]);
    }

    /**
     * Record the decision on the batch's gate. The engine's SoD refusal (the requester cannot decide
     * their own batch) is re-surfaced as the established DUAL_CONTROL_REQUIRED code. A legacy batch with
     * no gate (pre-engine) gets one lazily, anchored on its recorded proposer.
     */
    private function decideGate(string $batchId, bool $approve, ?User $actor): void
    {
        $gate = $this->reversalGate($batchId);
        if (! $gate) {
            $batch = DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->first();
            $gate = $this->openReversalGate($batchId, $batch->operator_code, $batch->proposed_by);
        }
        try {
            $this->approvals->decide($gate, $approve, $actor);
        } catch (DomainException $e) {
            if ($e->errorCode === 'SELF_APPROVAL_NOT_ALLOWED') {
                throw DomainException::ruleRejected('DUAL_CONTROL_REQUIRED', 'The approver must differ from the proposer.');
            }
            throw $e;
        }
    }

    /** The open EM-CFG-04 gate for this batch, if any. */
    private function reversalGate(string $batchId): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('entity_type', 'BULK_REVERSAL')
            ->where('entity_ref', $batchId)
            ->where('status', ApprovalRequest::PENDING)
            ->latest('created_at')->first();
    }

    /** Stable string handle for the deciding user, for the batch's approved_by audit column. */
    private function actorRef(?User $actor): ?string
    {
        return $actor?->uid ?? $actor?->email;
    }

    private function scopeQuery(array $scope, string $operator)
    {
        return Invoice::query()
            ->where('operator_code', $operator)
            ->when($scope['invoice_type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($scope['date_from'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '>=', $d))
            ->when($scope['date_to'] ?? null, fn ($q, $d) => $q->whereDate('issue_date', '<=', $d))
            ->when($scope['filters']['package_ref'] ?? null, fn ($q, $p) => $q->whereHas('lines', fn ($l) => $l->where('package_ref', $p)));
    }

    /** R-GEN-01-R-4: signed tax invoices, already-cancelled, and note-linked invoices are protected. */
    private function protectedReason(Invoice $invoice): ?string
    {
        return match (true) {
            $invoice->type === 'TAX' => 'SIGNED_TAX_INVOICE',
            $invoice->status === Invoice::VOID => 'ALREADY_CANCELLED',
            Invoice::query()->where('original_invoice_id', $invoice->invoice_id)->exists() => 'HAS_LINKED_NOTES',
            default => null,
        };
    }

    private function assertStatus(string $batchId, string $expected): object
    {
        $batch = DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->first();
        if (! $batch) {
            throw DomainException::notFound('Bulk reversal batch not found.');
        }
        if ($batch->status !== $expected) {
            throw DomainException::conflict("Batch is {$batch->status}; expected {$expected}.");
        }

        return $batch;
    }
}
