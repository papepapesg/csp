<?php

namespace Modules\Billing\Services;

use Modules\Billing\Wallet\Services\WalletService;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\RatedEvent;
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
        private readonly ChargeComputeService $charges,
        private readonly GenerationFailureService $failures,
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
                // R-GEN-01-Q-1: recoverable failure → persisted with context for a
                // backed-off retry + admin visibility, not lost.
                $this->failures->enqueue(
                    $operator, 'CYCLE_POSTPAID', $subscription->subscription_id,
                    ['subscriptionId' => $subscription->subscription_id],
                    $e instanceof \App\Foundation\Errors\DomainException ? $e->errorCode : 'GENERATION_FAILED',
                    $e->getMessage(),
                );
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

            // BIL-01 computes the typed charges (cyclical fee + per-category USAGE);
            // BIL-03 only orchestrates the boundary.
            ['charges' => $charges, 'ratedIdsByCategory' => $ratedIdsByCategory] = $this->charges->cycleCharges($subscription);
            $ratedIds = array_merge(...array_values($ratedIdsByCategory ?: [[]]));
            $total = round(array_sum(array_map(fn (Charge $c) => $c->amount, $charges)), 2);

            if ($total <= 0) {
                $this->advanceAnchor($subscription); // nothing owed — move the window on

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
                RatedEvent::query()->whereIn('rated_id', $ratedIds)->update(['billed' => true]);
                $this->advanceAnchor($subscription);
                $this->emit($subscription, BillingEvents::CYCLE_ACTIVATED, ['amount' => (string) $total, 'settlement' => 'WALLET']);

                return true;
            }

            // POSTPAID: BIL-02-GEN-01 builds the cycle invoice(s) from the charges,
            // SUMMARY/DETAIL structured and grouped per the operator policy.
            $invoices = $this->invoices->generateFromCharges(
                ['account_id' => $subscription->account_id, 'customer_id' => $subscription->customer_id, 'subscription_id' => $subscriptionId, 'currency' => $subscription->currency ?? 'KES'],
                $charges,
                'CYCLE_POSTPAID',
            );

            // RAT-01 mark-invoiced: each rated usage id is linked to THE invoice
            // that carries its category line — a grouping split (e.g. voice on its
            // own document) links voice calls to the voice invoice. This link is
            // what the itemized usage pages are built from.
            foreach ($invoices as $invoice) {
                $categories = $invoice->lines()->where('line_type', 'DETAIL')->pluck('service_category_code')->all();
                $ids = array_merge(...array_values(array_intersect_key($ratedIdsByCategory, array_flip($categories)) ?: [[]]));
                if ($ids !== []) {
                    RatedEvent::query()->whereIn('rated_id', $ids)->update(['billed' => true, 'invoice_id' => $invoice->invoice_id]);
                }
            }
            $this->advanceAnchor($subscription);
            $this->failures->resolveFor($subscription->operator_code, $subscriptionId, 'CYCLE_POSTPAID');
            $this->emit($subscription, BillingEvents::CYCLE_CLOSED, ['invoiceIds' => $invoices->pluck('invoice_id')->all(), 'total' => (string) $total]);

            return true;
        });
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
