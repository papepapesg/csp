<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\AccountCreditBalance;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentLedger;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-01-PAY-01 payment application. Owns inbound money: receive a payment
 * (idempotent on account+reference, RC-3), resolve the billing mode (RC-4) —
 * PREPAID credits the wallet (BIL-05), POSTPAID allocates across open invoices
 * by the operator policy (AL-2), excluding DISPUTED (AL-4) — handle overpayment
 * surplus per policy (OV), and support reversal (RV). Emits PaymentApplied /
 * PaymentReceivedUnallocated / PaymentReversed through the outbox.
 */
class PaymentService
{
    /** Invoice statuses eligible for auto-allocation (AL-4); DISPUTED/VOID excluded. */
    private const ALLOCATABLE = [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE];

    public function __construct(
        private readonly EventBus $events,
        private readonly DunningService $dunning,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param  array<string,mixed>  $data  account_id, paid_amount, method, currency?, payment_reference?, target_invoice_id?, customer_id?
     */
    public function receiveAndApply(array $data): PaymentLedger
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $reference = $data['payment_reference'] ?? $data['gateway_ref'] ?? null;

        // RC-3 idempotency on (account_id, payment_reference): a retried receipt
        // returns the prior result without re-applying.
        if ($reference) {
            $prior = PaymentLedger::query()->where('account_id', $data['account_id'])
                ->where('payment_reference', $reference)->first();
            if ($prior) {
                return $prior->load('allocations');
            }
        }

        // RC-4 billing-mode resolution: a PREPAID subscription on the account
        // routes to a wallet top-up (BIL-05) unless an invoice was explicitly targeted.
        $prepaid = empty($data['target_invoice_id'])
            ? Subscription::query()->where('account_id', $data['account_id'])
                ->where('billing_mode', 'PREPAID')->whereNotIn('status_code', [Subscription::TERMINATED])->first()
            : null;

        return DB::transaction(function () use ($data, $operator, $reference, $prepaid) {
            $amount = round((float) $data['paid_amount'], 2);
            $currency = $data['currency'] ?? 'KES';

            $payment = PaymentLedger::query()->create([
                'account_id' => $data['account_id'],
                'customer_id' => $data['customer_id'] ?? $prepaid?->customer_id,
                'operator_code' => $operator,
                'method' => $data['method'] ?? 'OFFLINE',
                'gateway_ref' => $data['gateway_ref'] ?? null,
                'payment_reference' => $reference,
                'currency' => $currency,
                'paid_amount' => $amount,
                'unallocated_amount' => $amount,
                'status' => 'RECEIVED',
                'received_at' => now(),
            ]);

            $this->events->publish($this->event(BillingEvents::PAYMENT_RECEIVED, $payment, ['amount' => (string) $amount]));

            // PREPAID path (AL-3): credit the wallet, done.
            if ($prepaid) {
                $wallet = $this->wallets->ensureWallet($prepaid->subscription_id, WalletService::DEFAULT_WALLET_CODE, $data['account_id'], $prepaid->customer_id);
                $this->wallets->credit($wallet, $amount, 'TOPUP', $reference);
                $payment->update(['unallocated_amount' => 0, 'status' => 'APPLIED']);

                return $payment->refresh()->load('allocations');
            }

            // POSTPAID allocation by operator policy (AL-2), customer-directed first.
            $policy = (string) (DB::table('payment_config')->where('operator_code', $operator)->value('allocation_policy') ?? 'FIFO_DUE_DATE');
            $invoices = $this->allocatableInvoices($data['account_id'], $data['target_invoice_id'] ?? null, $policy)->lockForUpdate()->get();

            $remaining = $amount;
            foreach ($invoices as $invoice) {
                if ($remaining <= 0.0001) {
                    break;
                }
                $applied = round(min($remaining, (float) $invoice->amount_due), 2);
                if ($applied <= 0) {
                    continue;
                }
                $before = (float) $invoice->amount_due;
                $payment->allocations()->create([
                    'invoice_id' => $invoice->invoice_id,
                    'allocated_amount' => $applied,
                    'outstanding_before' => $before,
                    'outstanding_after' => round($before - $applied, 2),
                    'allocation_strategy' => $data['target_invoice_id'] ?? false ? 'DIRECTED' : $policy,
                ]);
                $newPaid = (float) $invoice->amount_paid + $applied;
                $newDue = round((float) $invoice->total_amount - $newPaid, 2);
                $invoice->update([
                    'amount_paid' => $newPaid,
                    'amount_due' => max($newDue, 0),
                    'status' => $newDue <= 0.0001 ? Invoice::PAID : Invoice::PARTIALLY_PAID,
                ]);
                $this->events->publish($this->event(
                    $newDue <= 0.0001 ? BillingEvents::INVOICE_PAID : BillingEvents::PAYMENT_APPLIED,
                    $payment, ['invoiceId' => $invoice->invoice_id, 'applied' => (string) $applied, 'outstandingAfter' => (string) max($newDue, 0)],
                ));
                $remaining = round($remaining - $applied, 2);
            }

            $this->settleSurplus($payment, $data['account_id'], $operator, $currency, $remaining, $amount);
            $this->clearDunningIfPaid($data['account_id']);

            return $payment->refresh()->load('allocations');
        });
    }

    /**
     * RV: reverse a previously-applied payment — undo allocations, restore invoice
     * outstanding, debit any prepaid wallet credit, write a REVERSED lineage row.
     */
    public function reverse(PaymentLedger $payment, string $reasonCode, ?string $actor = null): PaymentLedger
    {
        if ($payment->status === 'REVERSED') {
            throw DomainException::conflict('Payment is already reversed.');
        }

        return DB::transaction(function () use ($payment, $reasonCode, $actor) {
            $reversedAllocations = [];
            foreach ($payment->allocations as $alloc) {
                $invoice = Invoice::query()->whereKey($alloc->invoice_id)->lockForUpdate()->first();
                if ($invoice) {
                    $newPaid = round((float) $invoice->amount_paid - (float) $alloc->allocated_amount, 2);
                    $newDue = round((float) $invoice->total_amount - $newPaid, 2);
                    $invoice->update([
                        'amount_paid' => max($newPaid, 0),
                        'amount_due' => max($newDue, 0),
                        'status' => $newDue <= 0.0001 ? Invoice::PAID : ($newPaid <= 0.0001 ? Invoice::OPEN : Invoice::PARTIALLY_PAID),
                    ]);
                }
                $reversedAllocations[] = ['invoice_id' => $alloc->invoice_id, 'amount' => (string) $alloc->allocated_amount];
            }

            // Surplus that went to the credit balance is debited back (best-effort, RV-4).
            if ((float) $payment->unallocated_amount > 0) {
                $credit = AccountCreditBalance::query()->find($payment->account_id);
                if ($credit) {
                    $credit->update(['balance' => max(round((float) $credit->balance - (float) $payment->unallocated_amount, 2), 0)]);
                }
            }

            $payment->update(['status' => 'REVERSED', 'reversal_reason_code' => $reasonCode, 'reversed_by' => $actor]);
            $reversal = PaymentLedger::query()->create([
                'account_id' => $payment->account_id, 'customer_id' => $payment->customer_id, 'operator_code' => $payment->operator_code,
                'method' => $payment->method, 'currency' => $payment->currency,
                'paid_amount' => -1 * (float) $payment->paid_amount, 'unallocated_amount' => 0,
                'status' => 'REVERSED', 'reversal_of_payment_id' => $payment->payment_id,
                'reversal_reason_code' => $reasonCode, 'reversed_by' => $actor, 'received_at' => now(),
            ]);

            $this->events->publish($this->event(BillingEvents::PAYMENT_REVERSED, $payment, [
                'reversalId' => $reversal->payment_id, 'reversedAmount' => (string) $payment->paid_amount,
                'reversedAllocations' => $reversedAllocations, 'reasonCode' => $reasonCode,
            ]));

            return $reversal;
        });
    }

    /** Manually allocate a held (MANUAL_REVIEW) overpayment's surplus to the credit balance. */
    public function allocateSurplus(PaymentLedger $payment): PaymentLedger
    {
        return DB::transaction(function () use ($payment) {
            $this->settleSurplus($payment, $payment->account_id, $payment->operator_code, $payment->currency, (float) $payment->unallocated_amount, (float) $payment->paid_amount, force: true);

            return $payment->refresh();
        });
    }

    /** Surplus handling per OV policy. */
    private function settleSurplus(PaymentLedger $payment, string $accountId, string $operator, string $currency, float $remaining, float $amount, bool $force = false): void
    {
        if ($remaining <= 0.0001) {
            $payment->update(['unallocated_amount' => 0, 'status' => 'APPLIED']);

            return;
        }

        $policy = (string) (DB::table('payment_config')->where('operator_code', $operator)->value('overpayment_policy') ?? 'APPLY_TO_NEXT_OPEN');

        // OV-4 MANUAL_REVIEW: hold the surplus for an admin (unless forced via /allocate-surplus).
        if ($policy === 'MANUAL_REVIEW' && ! $force) {
            $payment->update(['unallocated_amount' => $remaining, 'status' => $remaining >= $amount ? 'RECEIVED' : 'PARTIALLY_APPLIED']);
            $this->events->publish($this->event(BillingEvents::OVERPAYMENT_PENDING_REVIEW, $payment, ['surplus' => (string) $remaining]));

            return;
        }

        // OV-5: a payment with no allocation target at all is UNALLOCATED, not rejected.
        if ($remaining >= $amount) {
            $payment->update(['unallocated_amount' => $remaining, 'status' => 'UNALLOCATED']);
            $this->events->publish($this->event(BillingEvents::PAYMENT_RECEIVED_UNALLOCATED, $payment, ['amount' => (string) $remaining, 'reason' => 'NO_OPEN_INVOICES_NO_WALLET']));
        }

        // OV-1/2 APPLY_TO_NEXT_OPEN (default): surplus → account credit balance.
        $credit = AccountCreditBalance::query()->firstOrNew(['account_id' => $accountId]);
        $before = (float) ($credit->balance ?? 0);
        $credit->operator_code = $operator;
        $credit->currency = $currency;
        $credit->balance = round($before + $remaining, 2);
        $credit->save();
        $this->events->publish($this->event(BillingEvents::CREDIT_BALANCE_ADJUSTED, $payment, [
            'direction' => 'CREDIT', 'amount' => (string) $remaining, 'balanceBefore' => (string) $before,
            'balanceAfter' => (string) $credit->balance, 'reasonCode' => 'PAYMENT_OVERPAYMENT_SURPLUS',
        ]));

        if ($payment->status !== 'UNALLOCATED') {
            $payment->update(['unallocated_amount' => $remaining, 'status' => $remaining > 0 && $remaining < $amount ? 'PARTIALLY_APPLIED' : 'APPLIED']);
        }
    }

    /** Allocatable invoices in the operator's policy order (AL-2/AL-4). */
    private function allocatableInvoices(string $accountId, ?string $targetInvoiceId, string $policy)
    {
        $q = Invoice::query()
            ->where('account_id', $accountId)
            ->whereIn('status', self::ALLOCATABLE)
            ->where('amount_due', '>', 0)
            ->when($targetInvoiceId, fn ($q) => $q->where('invoice_id', $targetInvoiceId));

        return match ($policy) {
            'FIFO_ISSUE_DATE' => $q->orderBy('issue_date'),
            'LARGEST_FIRST' => $q->orderByDesc('amount_due'),
            'SMALLEST_FIRST' => $q->orderBy('amount_due'),
            default => $q->orderBy('due_date'), // FIFO_DUE_DATE
        };
    }

    private function clearDunningIfPaid(string $accountId): void
    {
        $stillOwed = Invoice::query()->where('account_id', $accountId)
            ->whereIn('status', self::ALLOCATABLE)->where('amount_due', '>', 0)->exists();
        if (! $stillOwed) {
            $this->dunning->clear($accountId);
        }
    }

    /** @param array<string,mixed> $extra */
    private function event(string $type, PaymentLedger $payment, array $extra): DomainEvent
    {
        return new DomainEvent(
            type: $type,
            topic: BillingEvents::TOPIC,
            payload: array_merge(['paymentId' => $payment->payment_id, 'accountId' => $payment->account_id, 'customerId' => $payment->customer_id], $extra),
            aggregateType: 'Payment',
            aggregateId: $payment->payment_id,
        );
    }
}
