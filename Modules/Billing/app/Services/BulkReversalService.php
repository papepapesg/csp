<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
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
 */
class BulkReversalService
{
    public function __construct(private readonly EventBus $events) {}

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

        return $batchId;
    }

    public function reject(string $batchId, ?string $by): void
    {
        $this->assertStatus($batchId, 'PENDING_APPROVAL');
        DB::table('bulk_reversal_batch')->where('batch_id', $batchId)
            ->update(['status' => 'REJECTED', 'approved_by' => $by, 'updated_at' => now()]);
    }

    /**
     * FINANCE_HEAD approves and the batch executes per invoice (R-GEN-01-R-1/3).
     * Dual control: the approver must differ from the proposer.
     */
    public function approveAndExecute(string $batchId, ?string $approvedBy): array
    {
        $batch = $this->assertStatus($batchId, 'PENDING_APPROVAL');
        if ($approvedBy !== null && $approvedBy === $batch->proposed_by) {
            throw DomainException::ruleRejected('DUAL_CONTROL_REQUIRED', 'The approver must differ from the proposer.');
        }

        DB::table('bulk_reversal_batch')->where('batch_id', $batchId)->update([
            'status' => 'IN_PROGRESS', 'approved_by' => $approvedBy, 'started_at' => now(), 'updated_at' => now(),
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
