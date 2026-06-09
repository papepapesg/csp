<?php

namespace Modules\Billing\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\AccountCreditBalance;
use Modules\Billing\Models\BillingIntent;

/**
 * BIL-01 billable-event intent service. A subscription operation calls emit() in
 * its commit window; the service records the intent and, for a positive
 * chargeable amount, raises a fee/proration invoice. Pay-first intents stay
 * PENDING until the invoice is settled (the workflow parks on AWAITING_PAYMENT);
 * non-pay-first or zero/credit intents auto-CONFIRM so the flow proceeds. A credit
 * (negative amount, e.g. downgrade proration / deposit refund) posts account credit.
 */
class BillingIntentService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly InvoiceService $invoices,
        private readonly WalletService $wallets,
    ) {}

    /**
     * @param  array<string,mixed>  $data  subscription_id, account_id, operation_id, intent_type, amount, currency?, pay_first?, description?
     */
    public function emit(array $data): BillingIntent
    {
        return DB::transaction(function () use ($data) {
            $amount = round((float) ($data['amount'] ?? 0), 2);
            $billingMode = $data['billing_mode'] ?? 'POSTPAID';
            $operator = $data['operator_code'] ?? Context::operatorCode();
            $payFirst = (bool) ($data['pay_first'] ?? false) && $amount > 0;

            $intent = BillingIntent::query()->create([
                'intent_id' => Id::make('bint'),
                'subscription_id' => $data['subscription_id'],
                'account_id' => $data['account_id'] ?? null,
                'operation_id' => $data['operation_id'] ?? null,
                'intent_type' => $data['intent_type'],
                'amount' => $amount,
                'currency' => $data['currency'] ?? 'KES',
                'pay_first' => $payFirst,
                'status' => BillingIntent::PENDING,
                'settlement_channel' => 'NONE',
            ]);

            if ($amount > 0 && $billingMode === 'PREPAID') {
                // Prepaid: charge the customer's prepaid wallet (PLM-CFG-03) instead of
                // raising an invoice. If the balance covers it, settle inline and the
                // operation proceeds; if not, stay PENDING (pay-first) so the flow parks
                // on AWAITING_PAYMENT until a top-up settles it (ConfirmPrepaidIntentOnTopup).
                $result = $this->wallets->settleFromWallets($data['subscription_id'], $amount, $data['intent_type'], $operator, $data['operation_id'] ?? null);
                if ($result['settled']) {
                    $intent->update(['settlement_channel' => 'WALLET', 'status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
                } else {
                    $intent->update(['settlement_channel' => 'WALLET', 'pay_first' => true, 'status' => BillingIntent::PENDING]);
                }
            } elseif ($amount > 0 && ! empty($data['account_id'])) {
                // Postpaid: raise a fee/proration invoice (BIL-01).
                $invoice = $this->invoices->generate(
                    ['account_id' => $data['account_id'], 'subscription_id' => $data['subscription_id'], 'type' => 'STANDARD'],
                    [['description' => $data['description'] ?? $data['intent_type'], 'quantity' => 1, 'unit_price' => $amount]],
                );
                $intent->update(['invoice_id' => $invoice->invoice_id, 'settlement_channel' => 'INVOICE', 'status' => $payFirst ? BillingIntent::PENDING : BillingIntent::CHARGED]);
            } elseif ($amount < 0 && ! empty($data['account_id'])) {
                // Credit/refund: post to account credit balance.
                $credit = AccountCreditBalance::query()->firstOrNew(['account_id' => $data['account_id']]);
                $credit->operator_code = $intent->operator_code;
                $credit->currency = $intent->currency;
                $credit->balance = (float) ($credit->balance ?? 0) + abs($amount);
                $credit->save();
                $intent->update(['settlement_channel' => 'CREDIT', 'status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
            } else {
                // Zero amount: nothing to gate.
                $intent->update(['status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
            }

            $this->emitEvent($intent, 'SubscriptionBillingIntentEmitted');

            return $intent->refresh();
        });
    }

    /** Mark an intent confirmed (its fee invoice was settled). */
    public function confirm(BillingIntent $intent): BillingIntent
    {
        if ($intent->status === BillingIntent::CONFIRMED) {
            return $intent;
        }
        $intent->update(['status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
        $this->emitEvent($intent, 'SubscriptionBillingIntentConfirmed');

        return $intent;
    }

    private function emitEvent(BillingIntent $intent, string $type): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: BillingEvents::TOPIC,
            payload: ['intentId' => $intent->intent_id, 'subscriptionId' => $intent->subscription_id, 'operationId' => $intent->operation_id, 'amount' => (string) $intent->amount, 'status' => $intent->status],
            aggregateType: 'BillingIntent',
            aggregateId: $intent->intent_id,
        ));
    }
}
