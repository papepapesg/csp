<?php

namespace Modules\Billing\Payments\Services;

use Modules\Billing\Wallet\Services\WalletService;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Payments\Models\AccountCreditBalance;
use Modules\Billing\Models\AdjustmentRequest;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Payments\Models\NoteApplication;
use Modules\Billing\Wallet\Models\Wallet;

/**
 * BIL-01-CN-01 note application — actually moves the money once GEN-01 has
 * issued a credit / debit note:
 *
 *  - POSTPAID credit: reduce the target invoice's outstanding by
 *    min(amount, outstanding); surplus goes to account_credit_balance. With no
 *    target invoice, auto-allocate FIFO by due date across open invoices.
 *  - POSTPAID debit: increase the target invoice's outstanding (a PAID invoice
 *    drops back to PARTIALLY_PAID).
 *  - PREPAID credit / debit: credit or debit the customer's wallet (BIL-05). An
 *    insufficient wallet FAILS the application (never made negative, no
 *    auto-retry — admin decides via /retry-application).
 *
 * Every application is one note_application_ledger row; a note that splits
 * (invoice + surplus, or auto-allocation) writes several rows in ONE
 * transaction. Idempotent on note_id: an already-APPLIED note is not re-applied.
 */
class NoteApplicationService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly WalletService $wallets,
    ) {}

    /**
     * Apply an issued note. Returns the ledger rows written (or the existing
     * rows when the note was already applied — R-CN-01-CN-3 idempotency).
     *
     * @return array{status:string, applications:array<int,NoteApplication>, failure_reason:?string}
     */
    public function apply(Invoice $note, AdjustmentRequest $adjustment): array
    {
        // Idempotency on note_id: an APPLIED note acks and returns as-is.
        $existing = NoteApplication::query()
            ->where('note_id', $note->invoice_id)
            ->where('status', NoteApplication::APPLIED)
            ->get();
        if ($existing->isNotEmpty()) {
            return ['status' => NoteApplication::APPLIED, 'applications' => $existing->all(), 'failure_reason' => null];
        }

        $noteType = $note->type === Invoice::CREDIT_NOTE ? 'CREDIT' : 'DEBIT';

        return DB::transaction(function () use ($note, $adjustment, $noteType) {
            return $adjustment->billing_mode === 'PREPAID'
                ? $this->applyToWallet($note, $adjustment, $noteType)
                : $this->applyToInvoices($note, $adjustment, $noteType);
        });
    }

    /** POSTPAID: apply against invoice outstanding (R-CN-01-AP-1 / AP-2). */
    private function applyToInvoices(Invoice $note, AdjustmentRequest $adjustment, string $noteType): array
    {
        $amount = (float) $note->total_amount;
        $applications = [];

        if ($noteType === 'DEBIT' || $adjustment->parent_invoice_id) {
            $target = Invoice::query()->whereKey($adjustment->parent_invoice_id)->lockForUpdate()->first();

            if (! $target) {
                return $this->fail($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $adjustment->parent_invoice_id, $amount, 0, 'INVOICE_NOT_FOUND');
            }
            if (in_array($target->status, [Invoice::VOID], true)) {
                return $this->fail($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $target->invoice_id, $amount, (float) $target->amount_due, 'INVOICE_NOT_APPLIABLE');
            }
            if ($target->currency !== $note->currency) {
                return $this->fail($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $target->invoice_id, $amount, (float) $target->amount_due, 'CURRENCY_MISMATCH');
            }

            if ($noteType === 'DEBIT') {
                // Debit note: outstanding grows by the full approved amount; a PAID
                // invoice becomes payable again (R-CN-01-AP-2).
                $before = (float) $target->amount_due;
                $after = round($before + $amount, 2);
                $target->update([
                    'amount_due' => $after,
                    'status' => $target->status === Invoice::PAID ? Invoice::PARTIALLY_PAID : $target->status,
                ]);
                $applications[] = $this->record($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $target->invoice_id, $amount, $before, $after);

                return $this->done($applications);
            }

            // Credit note against a specific invoice: cap at the outstanding.
            $before = (float) $target->amount_due;
            $applied = round(min($amount, $before), 2);
            if ($applied > 0) {
                $after = round($before - $applied, 2);
                $target->update([
                    'amount_due' => $after,
                    'status' => $after <= 0.0001 ? Invoice::PAID : Invoice::PARTIALLY_PAID,
                ]);
                $applications[] = $this->record($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $target->invoice_id, $applied, $before, $after);
            }
            $amount = round($amount - $applied, 2);
        } elseif ($noteType === 'CREDIT') {
            // Auto-allocated credit note: FIFO by due date across open invoices
            // (R-CN-01-AP-1, PAY-01's allocation policy).
            $open = Invoice::query()
                ->where('account_id', $adjustment->account_id)
                ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
                ->where('amount_due', '>', 0)
                ->orderBy('due_date')
                ->lockForUpdate()
                ->get();

            foreach ($open as $target) {
                if ($amount <= 0.0001) {
                    break;
                }
                if ($target->currency !== $note->currency) {
                    continue; // defensive (R-CN-01-FA-5); should not happen if ADJ-01 generated correctly
                }
                $before = (float) $target->amount_due;
                $applied = round(min($amount, $before), 2);
                $after = round($before - $applied, 2);
                $target->update([
                    'amount_due' => $after,
                    'status' => $after <= 0.0001 ? Invoice::PAID : Invoice::PARTIALLY_PAID,
                ]);
                $applications[] = $this->record($note, $adjustment, $noteType, NoteApplication::TARGET_INVOICE,
                    $target->invoice_id, $applied, $before, $after);
                $amount = round($amount - $applied, 2);
            }
        }

        // Credit note surplus (or auto-credit with no open invoices, R-CN-01-FA-4)
        // goes to the account credit balance (owned by PAY-01).
        if ($noteType === 'CREDIT' && $amount > 0.0001) {
            $credit = AccountCreditBalance::query()->firstOrNew(['account_id' => $adjustment->account_id]);
            $before = (float) ($credit->balance ?? 0);
            $credit->operator_code = $adjustment->operator_code;
            $credit->currency = $note->currency;
            $credit->balance = round($before + $amount, 2);
            $credit->save();
            $applications[] = $this->record($note, $adjustment, $noteType, NoteApplication::TARGET_CREDIT_BALANCE,
                null, $amount, $before, (float) $credit->balance);
        }

        return $this->done($applications);
    }

    /** PREPAID: credit / debit the customer's wallet (R-CN-01-AP-3 / AP-4). */
    private function applyToWallet(Invoice $note, AdjustmentRequest $adjustment, string $noteType): array
    {
        $amount = (float) $note->total_amount;
        $walletCode = $adjustment->target_wallet_ref ?: WalletService::DEFAULT_WALLET_CODE;
        $wallet = $this->wallets->ensureWallet(
            (string) $adjustment->subscription_id, $walletCode, $adjustment->account_id, $adjustment->customer_id,
        );

        if ($wallet->currency !== $note->currency) {
            return $this->fail($note, $adjustment, $noteType, NoteApplication::TARGET_WALLET,
                $walletCode, $amount, (float) $wallet->balance, 'CURRENCY_MISMATCH');
        }

        $before = (float) $wallet->balance;

        if ($noteType === 'CREDIT') {
            $this->wallets->credit($wallet, $amount, 'CREDIT_NOTE', $note->invoice_id);
        } else {
            // A debit note never makes the wallet negative: insufficient balance
            // FAILS the application; admin decides next step (R-CN-01-FA-1).
            if ($before + 0.0001 < $amount) {
                return $this->fail($note, $adjustment, $noteType, NoteApplication::TARGET_WALLET,
                    $walletCode, $amount, $before, 'WALLET_INSUFFICIENT_BALANCE');
            }
            $this->wallets->debit($wallet, $amount, 'DEBIT_NOTE', $note->invoice_id);
        }

        $after = (float) Wallet::query()->whereKey($wallet->wallet_id)->value('balance');

        return $this->done([
            $this->record($note, $adjustment, $noteType, NoteApplication::TARGET_WALLET, $walletCode, $amount, $before, $after),
        ]);
    }

    private function record(
        Invoice $note, AdjustmentRequest $adjustment, string $noteType,
        string $targetKind, ?string $targetId, float $applied, float $before, float $after,
    ): NoteApplication {
        $row = NoteApplication::query()->create([
            'note_id' => $note->invoice_id,
            'note_type' => $noteType,
            'adjustment_request_id' => $adjustment->adjustment_id,
            'customer_id' => $adjustment->customer_id,
            'operator_code' => $adjustment->operator_code,
            'note_amount' => (float) $note->total_amount,
            'currency' => $note->currency,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
            'applied_amount' => $applied,
            'target_balance_before' => $before,
            'target_balance_after' => $after,
            'status' => NoteApplication::APPLIED,
            'applied_at' => now(),
        ]);

        // R-CN-01-EV-2/3: one Applied event PER application row.
        $this->events->publish(new DomainEvent(
            type: $noteType === 'CREDIT' ? BillingEvents::CREDIT_NOTE_APPLIED : BillingEvents::DEBIT_NOTE_APPLIED,
            topic: BillingEvents::TOPIC,
            payload: [
                'noteId' => $note->invoice_id,
                'adjustmentRequestId' => $adjustment->adjustment_id,
                'customerId' => $adjustment->customer_id,
                'target' => ['type' => $targetKind, 'targetId' => $targetId, 'appliedAmount' => (string) $applied, 'balanceAfter' => (string) $after],
                'currency' => $note->currency,
            ],
            aggregateType: 'Invoice',
            aggregateId: $note->invoice_id,
        ));

        return $row;
    }

    /** @param array<int,NoteApplication> $applications */
    private function done(array $applications): array
    {
        return ['status' => NoteApplication::APPLIED, 'applications' => $applications, 'failure_reason' => null];
    }

    private function fail(
        Invoice $note, AdjustmentRequest $adjustment, string $noteType,
        string $targetKind, ?string $targetId, float $attempted, float $targetBalance, string $reason,
    ): array {
        $row = NoteApplication::query()->create([
            'note_id' => $note->invoice_id,
            'note_type' => $noteType,
            'adjustment_request_id' => $adjustment->adjustment_id,
            'customer_id' => $adjustment->customer_id,
            'operator_code' => $adjustment->operator_code,
            'note_amount' => (float) $note->total_amount,
            'currency' => $note->currency,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
            'applied_amount' => 0,
            'target_balance_before' => $targetBalance,
            'target_balance_after' => $targetBalance,
            'status' => NoteApplication::FAILED,
            'failure_reason' => $reason,
            'applied_at' => now(),
        ]);

        // Wallet shortfalls get their dedicated alerting event (R-CN-01-EV-4);
        // everything else uses the generic failure event (R-CN-01-EV-5).
        $this->events->publish(new DomainEvent(
            type: $reason === 'WALLET_INSUFFICIENT_BALANCE'
                ? BillingEvents::DEBIT_NOTE_APPLICATION_FAILED
                : BillingEvents::NOTE_APPLICATION_FAILED,
            topic: BillingEvents::TOPIC,
            payload: [
                'noteId' => $note->invoice_id,
                'noteType' => $noteType,
                'adjustmentRequestId' => $adjustment->adjustment_id,
                'customerId' => $adjustment->customer_id,
                'target' => ['type' => $targetKind, 'targetId' => $targetId],
                'attemptedAmount' => (string) $attempted,
                'targetBalance' => (string) $targetBalance,
                'currency' => $note->currency,
                'reason' => $reason,
            ],
            aggregateType: 'Invoice',
            aggregateId: $note->invoice_id,
        ));

        return ['status' => NoteApplication::FAILED, 'applications' => [$row], 'failure_reason' => $reason];
    }
}
