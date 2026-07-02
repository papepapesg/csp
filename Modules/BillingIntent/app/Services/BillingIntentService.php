<?php

namespace Modules\Billing\Intent\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Intent\Models\BillingIntent;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Invoicing\Services\InvoiceService;
use Modules\Billing\Intent\Models\BillableEvent;
use Modules\Billing\Intent\Services\BillableEventCatalogService;
use Modules\Billing\Payments\Models\AccountCreditBalance;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;

/**
 * BIL-01 billable-event intent service. A subscription operation calls emit() in
 * its commit window; the service records the intent and settles its money side
 * through ONE of the channels:
 *
 *  - NONE    zero amount, or the catalog's applicability excludes this billing
 *            mode (R-B-5 skip) — nothing to charge, CONFIRMED immediately;
 *  - WALLET  prepaid charge — debit the wallet inline (CONFIRMED) or, when the
 *            balance is short, park PENDING until a top-up settles it
 *            (ConfirmPrepaidIntentOnTopup);
 *  - INVOICE postpaid charge — raise a fee/proration invoice; pay-first intents
 *            stay PENDING until it is paid (ConfirmBillingIntentOnPayment),
 *            others proceed as CHARGED;
 *  - CREDIT  negative amount (downgrade proration / deposit refund) — posted to
 *            the account credit balance, CONFIRMED immediately.
 *
 * BIL-CFG-01: when the operator governs intents through the BillableEvent
 * catalog, the intent must resolve to an ACTIVE event; sign policy (R-AS-*) is
 * enforced, the event's pay_first_required is the default gate, and its
 * state_callback (the SUB-LM transition the charge gates) is snapshotted on the
 * intent to fire once settlement confirms (R-BIL-01-SC-1).
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
            $billingMode = $data['billing_mode'] ?? BillingIntent::POSTPAID;
            $operator = $data['operator_code'] ?? Context::operatorCode();
            $payFirst = (bool) ($data['pay_first'] ?? false) && $amount > 0;

            $policy = $this->catalogPolicy($operator, (string) $data['intent_type'], $billingMode, $amount);
            if ($policy['pay_first_default'] !== null && ! array_key_exists('pay_first', $data)) {
                // The event's pay_first_required is the default when the
                // workflow config did not say otherwise.
                $payFirst = $policy['pay_first_default'] && $amount > 0;
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
                'settlement_channel' => BillingIntent::CHANNEL_NONE,
                'state_callback' => $policy['state_callback'],
            ]);

            $this->settle($intent, $data, $amount, $billingMode, $operator, $payFirst, $policy['skip_charge']);

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

    // ── Catalog governance (BIL-CFG-01) ─────────────────────────────────────

    /**
     * Resolve the operator's BillableEvent policy for this intent. Without a catalog
     * everything passes untouched. With one: an unknown event is refused; an event whose
     * applicability excludes this billing mode yields a charge SKIP (R-B-5 — acknowledge,
     * don't fail); a matched event enforces its sign policy and contributes its pay-first
     * default and state callback.
     *
     * @return array{skip_charge: bool, pay_first_default: ?bool, state_callback: ?array}
     */
    private function catalogPolicy(string $operator, string $intentType, string $billingMode, float $amount): array
    {
        $none = ['skip_charge' => false, 'pay_first_default' => null, 'state_callback' => null];

        if (! $this->catalog->operatorHasCatalog($operator)) {
            return $none;
        }

        $matched = $this->catalog->resolve($operator, $intentType, $billingMode);
        if ($matched->isEmpty()) {
            $any = $this->catalog->resolve($operator, $intentType, BillingIntent::PREPAID)
                ->merge($this->catalog->resolve($operator, $intentType, BillingIntent::POSTPAID));
            if ($any->isEmpty()) {
                throw DomainException::ruleRejected(
                    'UNKNOWN_BILLABLE_EVENT',
                    "Intent '{$intentType}' does not resolve to an ACTIVE BillableEvent for this operator.",
                );
            }

            // Event exists but its applicability excludes this billing mode
            // (e.g. PREPAID_ONLY against a postpaid subscription): skip, don't fail.
            return ['skip_charge' => true, 'pay_first_default' => null, 'state_callback' => null];
        }

        /** @var BillableEvent $event */
        $event = $matched->first();
        if ($event->amount_sign_policy === BillableEvent::POSITIVE_ONLY && $amount < 0) {
            throw DomainException::ruleRejected('AMOUNT_SIGN_VIOLATION', "BillableEvent {$event->code} is POSITIVE_ONLY; a negative amount is not allowed.");
        }
        if ($event->amount_sign_policy === BillableEvent::NEGATIVE_ONLY && $amount > 0) {
            throw DomainException::ruleRejected('AMOUNT_SIGN_VIOLATION', "BillableEvent {$event->code} is NEGATIVE_ONLY; a positive amount is not allowed.");
        }

        return [
            'skip_charge' => false,
            'pay_first_default' => (bool) $event->pay_first_required,
            // R-BIL-01-SC-1: snapshot the SUB-LM transition this event gates so it
            // fires once the charge settles (possibly on a later payment confirmation).
            'state_callback' => $event->state_callback,
        ];
    }

    // ── Settlement channels ──────────────────────────────────────────────────

    /** Route the intent's money side to its settlement channel (see the class docblock). */
    private function settle(BillingIntent $intent, array $data, float $amount, string $billingMode, string $operator, bool $payFirst, bool $skipCharge): void
    {
        if ($skipCharge) {
            // BIL-CFG-01 R-B-5: the event's applicability excludes this billing
            // mode — the intent is acknowledged without charging.
            $intent->update(['status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);

            return;
        }

        if ($amount > 0 && $billingMode === BillingIntent::PREPAID) {
            $this->settleFromWallet($intent, $data, $amount, $operator);

            return;
        }

        if ($amount > 0 && ! empty($data['account_id'])) {
            $this->chargeInvoice($intent, $data, $amount, $payFirst);

            return;
        }

        if ($amount < 0 && ! empty($data['account_id'])) {
            $this->postAccountCredit($intent, $data, $amount);

            return;
        }

        // Zero amount: nothing to gate.
        $intent->update(['status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
    }

    /**
     * Prepaid: charge the customer's prepaid wallet (PLM-CFG-03) instead of raising an
     * invoice. If the balance covers it, settle inline and the operation proceeds; if
     * not, stay PENDING (pay-first) so the flow parks on AWAITING_PAYMENT until a
     * top-up settles it (ConfirmPrepaidIntentOnTopup).
     */
    private function settleFromWallet(BillingIntent $intent, array $data, float $amount, string $operator): void
    {
        $result = $this->wallets->settleFromWallets($data['subscription_id'], $amount, $data['intent_type'], $operator, $data['operation_id'] ?? null);

        $intent->update($result['settled']
            ? ['settlement_channel' => BillingIntent::CHANNEL_WALLET, 'status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]
            : ['settlement_channel' => BillingIntent::CHANNEL_WALLET, 'pay_first' => true, 'status' => BillingIntent::PENDING]);
    }

    /** Postpaid: raise a fee/proration invoice (BIL-01); pay-first keeps the gate closed until it is paid. */
    private function chargeInvoice(BillingIntent $intent, array $data, float $amount, bool $payFirst): void
    {
        $invoice = $this->invoices->generate(
            ['account_id' => $data['account_id'], 'subscription_id' => $data['subscription_id'], 'type' => Invoice::STANDARD],
            [['description' => $data['description'] ?? $data['intent_type'], 'quantity' => 1, 'unit_price' => $amount]],
        );

        $intent->update([
            'invoice_id' => $invoice->invoice_id,
            'settlement_channel' => BillingIntent::CHANNEL_INVOICE,
            'status' => $payFirst ? BillingIntent::PENDING : BillingIntent::CHARGED,
        ]);
    }

    /** Credit/refund (negative amount, e.g. downgrade proration / deposit refund): post to account credit. */
    private function postAccountCredit(BillingIntent $intent, array $data, float $amount): void
    {
        $credit = AccountCreditBalance::query()->firstOrNew(['account_id' => $data['account_id']]);
        $credit->operator_code = $intent->operator_code;
        $credit->currency = $intent->currency;
        $credit->balance = (float) ($credit->balance ?? 0) + abs($amount);
        $credit->save();

        $intent->update(['settlement_channel' => BillingIntent::CHANNEL_CREDIT, 'status' => BillingIntent::CONFIRMED, 'confirmed_at' => now()]);
    }

    // ── Settlement side effects ──────────────────────────────────────────────

    /**
     * R-BIL-01-SC-1: a matched BillableEvent may pin a state_callback {transitionCode,
     * targetStatus}. Once its charge settles, BIL-01 drives the SUB-LM-01 transition it
     * gates (e.g. a paid reconnection fee flips SUSPENDED_NP back to ACTIVE). Idempotent:
     * a subscription already at the target status is left untouched. SubscriptionService
     * is resolved lazily — Subscription workflows call into BIL-01, so a constructor
     * dependency would be circular.
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
