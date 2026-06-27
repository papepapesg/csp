<?php

namespace Modules\Billing\Services;

use Modules\Billing\Wallet\Services\WalletService;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\RatedEvent;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-02 cycle billing: the consumer that closes the rating→settlement loop. At
 * cycle close it gathers each subscription's unbilled rated events and settles them
 * by billing mode — POSTPAID raises an invoice, PREPAID drains the wallet (by
 * charging precedence) — then flags the events billed. The two settlement rails the
 * tour described, now wired.
 */
class CycleBillingService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly InvoiceService $invoices,
        private readonly WalletService $wallets,
    ) {}

    /** @return array{subscriptions:int, billed:int} */
    public function run(?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $subscriptionIds = RatedEvent::query()
            ->where('operator_code', $operator)->where('billed', false)
            ->whereNotNull('subscription_id')->distinct()->pluck('subscription_id');

        $billed = 0;
        foreach ($subscriptionIds as $subscriptionId) {
            if ($this->billSubscription($subscriptionId)) {
                $billed++;
            }
        }

        return ['subscriptions' => $subscriptionIds->count(), 'billed' => $billed];
    }

    /**
     * Bill one subscription's unbilled rated events. Returns false when there is
     * nothing to bill, or (prepaid) the wallet can't cover it — the events stay
     * unbilled for the next cycle / top-up.
     */
    public function billSubscription(string $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::query()->find($subscriptionId);
            if (! $subscription) {
                return false;
            }
            $rated = RatedEvent::query()->where('subscription_id', $subscriptionId)->where('billed', false)->lockForUpdate()->get();
            if ($rated->isEmpty()) {
                return false;
            }
            $total = round((float) $rated->sum('amount'), 2);

            if (($subscription->billing_mode ?? 'POSTPAID') === 'PREPAID') {
                // Prepaid: drain the wallet (usage charges at cycle close).
                $result = $this->wallets->settleFromWallets($subscriptionId, $total, 'USAGE_CHARGE', $subscription->operator_code, 'cycle');
                if (! $result['settled']) {
                    return false; // insufficient balance — leave unbilled
                }
                $settlementRef = 'WALLET';
            } else {
                // Postpaid: raise an invoice, one line per rated event.
                $lines = $rated->map(fn (RatedEvent $e) => [
                    'description' => 'Usage — '.($e->tariff_code ?? 'RATED'),
                    'quantity' => 1, 'unit_price' => (float) $e->amount,
                ])->all();
                $invoice = $this->invoices->generate(
                    ['account_id' => $subscription->account_id, 'subscription_id' => $subscriptionId, 'currency' => $subscription->currency ?? 'KES'],
                    $lines,
                );
                $settlementRef = $invoice->invoice_id;
            }

            RatedEvent::query()->whereIn('rated_id', $rated->pluck('rated_id'))->update(['billed' => true]);

            $this->events->publish(new DomainEvent(
                type: BillingEvents::CYCLE_BILLED,
                topic: BillingEvents::TOPIC,
                payload: ['subscriptionId' => $subscriptionId, 'eventCount' => $rated->count(), 'total' => (string) $total, 'settlement' => $settlementRef],
                aggregateType: 'Subscription',
                aggregateId: $subscriptionId,
            ));

            return true;
        });
    }
}
