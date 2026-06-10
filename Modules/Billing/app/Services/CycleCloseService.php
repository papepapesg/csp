<?php

namespace Modules\Billing\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\RatedEvent;
use Modules\Catalog\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-03 cycle close engine — the per-subscription cycle-boundary scanner.
 *
 * Each active subscription carries a cycle window (current_cycle_start/end) on
 * the SUB-LM master. When `now()` reaches `current_cycle_end`, this engine
 * generates the cycle charge — the recurring package fee PLUS any accumulated
 * usage rated this cycle — settles it by billing mode, and advances the anchor.
 *
 * - POSTPAID  → raise the cycle invoice (BIL-02), emit CycleClosed, advance.
 * - PREPAID   → debit the wallet (BIL-05); paid → CycleActivated + advance,
 *               short → CyclePaymentMissed + FREEZE the anchor (it stays at the
 *               missed boundary until a top-up clears it; R-BIL-03-C-3/W-1).
 *
 * Idempotent on (subscription_id, current_cycle_end) via
 * last_cycle_closed_window_end, so a re-run never double-bills (R-BIL-03-C-5).
 * One boundary per evaluation — a backlogged subscription advances one cycle
 * per pass and is re-picked next run.
 */
class CycleCloseService
{
    /** R-BIL-03-E-4: cap a pass so it never monopolises downstream modules. */
    private const BATCH = 1000;

    public function __construct(
        private readonly EventBus $events,
        private readonly InvoiceService $invoices,
        private readonly WalletService $wallets,
    ) {}

    /** @return array{run_id:string, evaluated:int, closed:int, skipped:int, failed:int} */
    public function scan(?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $runId = Id::make('ccr');
        DB::table('cycle_close_run')->insert([
            'run_id' => $runId, 'operator_code' => $operator, 'started_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $due = Subscription::query()
            ->where('operator_code', $operator)
            ->where('status_code', Subscription::ACTIVE)
            ->whereNotNull('current_cycle_end')
            ->where('current_cycle_end', '<=', now())
            ->where(fn ($q) => $q->whereNull('last_cycle_closed_window_end')
                ->orWhereColumn('last_cycle_closed_window_end', '<', 'current_cycle_end'))
            ->orderBy('current_cycle_end')
            ->limit(self::BATCH)
            ->get();

        $closed = $skipped = $failed = 0;
        foreach ($due as $subscription) {
            try {
                $this->closeCycle($subscription->subscription_id) ? $closed++ : $skipped++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        DB::table('cycle_close_run')->where('run_id', $runId)->update([
            'completed_at' => now(),
            'subscriptions_evaluated' => $due->count(),
            'subscriptions_closed' => $closed,
            'subscriptions_skipped' => $skipped,
            'subscriptions_failed' => $failed,
            'updated_at' => now(),
        ]);

        return ['run_id' => $runId, 'evaluated' => $due->count(), 'closed' => $closed, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Close one subscription's current cycle. Returns true when the boundary was
     * settled + advanced, false when nothing was due or (prepaid) the wallet
     * could not cover the charge.
     */
    public function closeCycle(string $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId) {
            $subscription = Subscription::query()->whereKey($subscriptionId)->lockForUpdate()->first();
            if (! $subscription || ! $subscription->current_cycle_end || $subscription->current_cycle_end->isFuture()) {
                return false;
            }
            // R-BIL-03-C-5 idempotency: this window already closed.
            if ($subscription->last_cycle_closed_window_end
                && ! $subscription->last_cycle_closed_window_end->lt($subscription->current_cycle_end)) {
                return false;
            }

            $windowEnd = $subscription->current_cycle_end;
            $recurringFee = $this->recurringFee($subscription);
            $rated = RatedEvent::query()->where('subscription_id', $subscriptionId)->where('billed', false)->lockForUpdate()->get();
            $usageTotal = round((float) $rated->sum('amount'), 2);
            $total = round($recurringFee + $usageTotal, 2);

            if ($total <= 0) {
                // Nothing to charge this cycle — still advance so the window moves.
                $this->advanceAnchor($subscription);

                return true;
            }

            if (($subscription->billing_mode ?? 'POSTPAID') === 'PREPAID') {
                $result = $this->wallets->settleFromWallets($subscriptionId, $total, 'CYCLE_CHARGE', $subscription->operator_code, 'cycle:'.$windowEnd->toDateString());
                if (! $result['settled']) {
                    // R-BIL-03-C-3 / W-1: missed payment FREEZES the anchor (no
                    // advance) and drives dunning; it unfreezes on top-up.
                    $this->emit($subscription, BillingEvents::CYCLE_PAYMENT_MISSED, ['amountDue' => (string) $total, 'cycleEnd' => $windowEnd->toIso8601String()]);

                    return false;
                }
                RatedEvent::query()->whereIn('rated_id', $rated->pluck('rated_id'))->update(['billed' => true]);
                $this->advanceAnchor($subscription);
                $this->emit($subscription, BillingEvents::CYCLE_ACTIVATED, ['amount' => (string) $total, 'settlement' => 'WALLET']);

                return true;
            }

            // POSTPAID: one cycle invoice = recurring fee line + usage lines.
            $lines = [];
            if ($recurringFee > 0) {
                $lines[] = ['description' => 'Recurring fee — '.$subscription->package_ref, 'quantity' => 1, 'unit_price' => $recurringFee];
            }
            foreach ($rated as $e) {
                $lines[] = ['description' => 'Usage — '.($e->tariff_code ?? 'RATED'), 'quantity' => 1, 'unit_price' => (float) $e->amount];
            }
            $invoice = $this->invoices->generate(
                ['account_id' => $subscription->account_id, 'subscription_id' => $subscriptionId, 'currency' => $subscription->currency ?? 'KES'],
                $lines,
            );
            RatedEvent::query()->whereIn('rated_id', $rated->pluck('rated_id'))->update(['billed' => true]);
            $this->advanceAnchor($subscription);
            $this->emit($subscription, BillingEvents::CYCLE_CLOSED, ['invoiceId' => $invoice->invoice_id, 'recurringFee' => (string) $recurringFee, 'usage' => (string) $usageTotal, 'total' => (string) $total]);

            return true;
        });
    }

    /** The package's recurring price for this subscription (0 when unpriced). */
    private function recurringFee(Subscription $subscription): float
    {
        if (! $subscription->package_version_id) {
            return 0.0;
        }

        return (float) (PackageVersion::query()->whereKey($subscription->package_version_id)->value('price') ?? 0);
    }

    /** Move the window forward by one period; record the closed window (idempotency key). */
    private function advanceAnchor(Subscription $subscription): void
    {
        $newStart = $subscription->current_cycle_end;
        $subscription->update([
            'last_cycle_closed_window_end' => $subscription->current_cycle_end,
            'current_cycle_start' => $newStart,
            'current_cycle_end' => (clone $newStart)->add($subscription->cyclePeriod()),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function emit(Subscription $subscription, string $type, array $extra): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: BillingEvents::TOPIC,
            payload: array_merge(['subscriptionId' => $subscription->subscription_id, 'accountId' => $subscription->account_id, 'billingMode' => $subscription->billing_mode], $extra),
            aggregateType: 'Subscription',
            aggregateId: $subscription->subscription_id,
        ));
    }
}
