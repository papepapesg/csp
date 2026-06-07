<?php

namespace Modules\Billing\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\AccountCreditBalance;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentLedger;

/**
 * BIL-01-PAY-01 payment application. Receives money and allocates it across the
 * account's open invoices (customer-directed or auto FIFO by due date), records
 * surplus as account credit, and emits PaymentApplied / InvoicePaid.
 */
class PaymentService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * @param  array<string,mixed>  $data  account_id, paid_amount, method, currency?, gateway_ref?, target_invoice_id?
     */
    public function receiveAndApply(array $data): PaymentLedger
    {
        return DB::transaction(function () use ($data) {
            $operator = $data['operator_code'] ?? Context::operatorCode();
            $amount = (float) $data['paid_amount'];

            $payment = PaymentLedger::query()->create([
                'account_id' => $data['account_id'],
                'operator_code' => $operator,
                'method' => $data['method'] ?? 'OFFLINE',
                'gateway_ref' => $data['gateway_ref'] ?? null,
                'currency' => $data['currency'] ?? 'KES',
                'paid_amount' => $amount,
                'unallocated_amount' => $amount,
                'status' => 'RECEIVED',
                'received_at' => now(),
            ]);

            $this->events->publish(new DomainEvent(
                type: BillingEvents::PAYMENT_RECEIVED,
                topic: BillingEvents::TOPIC,
                payload: ['paymentId' => $payment->payment_id, 'accountId' => $payment->account_id, 'amount' => (string) $amount],
                aggregateType: 'Payment',
                aggregateId: $payment->payment_id,
            ));

            $remaining = $amount;

            // Target invoices: customer-directed first, otherwise auto FIFO by due date.
            $invoices = Invoice::query()
                ->where('account_id', $data['account_id'])
                ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
                ->when(! empty($data['target_invoice_id']), fn ($q) => $q->where('invoice_id', $data['target_invoice_id']))
                ->orderBy('due_date')
                ->lockForUpdate()
                ->get();

            foreach ($invoices as $invoice) {
                if ($remaining <= 0) {
                    break;
                }
                $applied = min($remaining, (float) $invoice->amount_due);
                if ($applied <= 0) {
                    continue;
                }

                $payment->allocations()->create([
                    'invoice_id' => $invoice->invoice_id,
                    'allocated_amount' => $applied,
                ]);

                $newPaid = (float) $invoice->amount_paid + $applied;
                $newDue = (float) $invoice->total_amount - $newPaid;
                $invoice->update([
                    'amount_paid' => $newPaid,
                    'amount_due' => max($newDue, 0),
                    'status' => $newDue <= 0.0001 ? Invoice::PAID : Invoice::PARTIALLY_PAID,
                ]);

                $this->events->publish(new DomainEvent(
                    type: $newDue <= 0.0001 ? BillingEvents::INVOICE_PAID : BillingEvents::PAYMENT_APPLIED,
                    topic: BillingEvents::TOPIC,
                    payload: ['invoiceId' => $invoice->invoice_id, 'paymentId' => $payment->payment_id, 'applied' => (string) $applied],
                    aggregateType: 'Invoice',
                    aggregateId: $invoice->invoice_id,
                ));

                $remaining -= $applied;
            }

            // Surplus -> account credit balance.
            if ($remaining > 0) {
                $credit = AccountCreditBalance::query()->firstOrNew(['account_id' => $data['account_id']]);
                $credit->operator_code = $operator;
                $credit->currency = $payment->currency;
                $credit->balance = (float) ($credit->balance ?? 0) + $remaining;
                $credit->save();
            }

            $payment->update([
                'unallocated_amount' => $remaining,
                'status' => $remaining >= $amount ? 'RECEIVED' : ($remaining > 0 ? 'PARTIALLY_APPLIED' : 'APPLIED'),
            ]);

            return $payment->refresh()->load('allocations');
        });
    }
}
