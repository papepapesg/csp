<?php

namespace Modules\Billing\Services;
use Modules\Billing\Mediation\Services\BillableEventCatalogService;
use Modules\Billing\Services\InvoiceService;

use Modules\Billing\Wallet\Services\WalletService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Payments\Models\AccountCreditBalance;
use Modules\Billing\Mediation\Models\BillableEvent;
use Modules\Billing\Models\BillingIntent;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;

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
        private readonly BillableEventCatalogService $catalog,
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

            // BIL-CFG-01: when the operator governs intents through the
            // BillableEvent catalog, the intent must resolve to an ACTIVE event;
            // applicability skips (R-B-5) and sign policy (R-AS-*) are enforced.
            $skipCharge = false;
            $stateCallback = null;
            if ($this->catalog->operatorHasCatalog($operator)) {
                $matched = $this->catalog->resolve($operator, (string) $data['intent_type'], $billingMode);
                if ($matched->isEmpty()) {
                    $any = $this->catalog->resolve($operator, (string) $data['intent_type'], 'PREPAID')
                        ->merge($this->catalog->resolve($operator, (string) $data['intent_type'], 'POSTPAID'));
                    if ($any->isEmpty()) {
                        throw DomainException::ruleRejected(
                            'UNKNOWN_BILLABLE_EVENT',
                            "Intent '{$data['intent_type']}' does not resolve to an ACTIVE BillableEvent for this operator.",
                        );
                    }
                    // Event exists but its applicability excludes this billing mode
                    // (e.g. PREPAID_ONLY against a postpaid subscription): skip, don't fail.
                    $skipCharge = true;
                } else {
                    /** @var BillableEvent $event */
                    $event = $matched->first();
                    if ($event->amount_sign_policy === 'POSITIVE_ONLY' && $amount < 0) {
                        throw DomainException::ruleRejected('AMOUNT_SIGN_VIOLATION', "BillableEvent {$event->code} is POSITIVE_ONLY; a negative amount is not allowed.");
                    }
                    if ($event->amount_sign_policy === 'NEGATIVE_ONLY' && $amount > 0) {
                        throw DomainException::ruleRejected('AMOUNT_SIGN_VIOLATION', "BillableEvent {$event->code} is NEGATIVE_ONLY; a positive amount is not allowed.");
                    }
                    // The event's pay_first_required is the default when the
                    // workflow config did not say otherwise.
                    if (! array_key_exists('pay_first', $data)) {
                        $payFirst = $event->pay_first_required && $amount > 0;
                    }
                    // R-BIL-01-SC-1: snapshot the SUB-LM transition this event gates so it
                    // fires once the charge settles (possibly on a later payment confirmation).
                    $stateCallback = $event->state_callback;
                }
            }

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
                'state_callback' => $stateCallback,
            ]);

            if ($skipCharge) {
                // BIL-CFG-01 R-B-5: the event's applicability excludes this billing
                // mode — the intent is acknowledged without charging.
                $intent->update(['status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
            } elseif ($amount > 0 && $billingMode === 'PREPAID') {
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

            // Inline-settled intents (prepaid paid / credit / zero / skip) apply their
            // state callback now; pay-first intents apply it later, on confirm().
            if ($intent->status === BillingIntent::CONFIRMED) {
                $this->applyStateCallback($intent);
            }

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
        // R-BIL-01-SC-1: settlement is now confirmed — drive the gated SUB-LM transition.
        $this->applyStateCallback($intent);

        return $intent;
    }

    /**
     * R-BIL-01-SC-1: a matched BillableEvent may pin a state_callback {transitionCode,
     * targetStatus}. Once its charge settles, BIL-01 drives the SUB-LM-01 transition it
     * gates (e.g. a paid reconnection fee flips SUSPENDED_NP back to ACTIVE). Idempotent:
     * a subscription already at the target status is left untouched.
     */
    private function applyStateCallback(BillingIntent $intent): void
    {
        $callback = $intent->state_callback;
        $target = $callback['targetStatus'] ?? null;
        if (! $target || ! $intent->subscription_id) {
            return;
        }
        $subscription = Subscription::query()->find($intent->subscription_id);
        if (! $subscription || $subscription->status_code === $target) {
            return;
        }
        app(SubscriptionService::class)->transitionStatus($subscription, $target);
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
