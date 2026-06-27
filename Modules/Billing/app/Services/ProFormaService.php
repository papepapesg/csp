<?php

namespace Modules\Billing\Services;
use Modules\Billing\Services\Charge;
use Modules\Billing\Services\ChargeComputeService;
use Modules\Billing\Services\CustomerSnapshotService;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-02-GEN-01 Generator 3 — pro-forma cycle documents. A scheduled scanner
 * generates a pro forma for each PREPAID subscription whose cycle ends within
 * the pre-cycle window, projecting the upcoming charges (recurring + run-rate
 * usage) so the customer knows what to top up. Informational: no legal number,
 * no receivable. A new pro forma supersedes the previous one for that cycle.
 */
class ProFormaService
{
    private const PRE_CYCLE_WINDOW_DAYS = 5;

    public function __construct(
        private readonly ChargeComputeService $charges,
        private readonly CustomerSnapshotService $snapshots,
    ) {}

    /** @return array{scanned:int, generated:int} */
    public function scan(?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $generated = 0;

        $due = Subscription::query()
            ->where('operator_code', $operator)
            ->where('status_code', Subscription::ACTIVE)
            ->where('billing_mode', 'PREPAID')
            ->whereNotNull('current_cycle_end')
            ->whereBetween('current_cycle_end', [now(), now()->addDays(self::PRE_CYCLE_WINDOW_DAYS)])
            ->get();

        foreach ($due as $subscription) {
            if ($this->generate($subscription)) {
                $generated++;
            }
        }

        return ['scanned' => $due->count(), 'generated' => $generated];
    }

    /** Generate (or supersede) the pro forma for a subscription's upcoming cycle. */
    public function generate(Subscription $subscription): bool
    {
        $cycleKey = 'cycle_'.$subscription->current_cycle_end->format('Y_m').'_sub_'.$subscription->subscription_id;
        if (DB::table('pro_forma')->where('subscription_id', $subscription->subscription_id)->where('idempotency_cycle_key', $cycleKey)->where('status', 'ACTIVE')->exists()) {
            return false; // already projected this cycle
        }

        ['charges' => $charges] = $this->charges->cycleCharges($subscription);
        $total = round(array_sum(array_map(fn (Charge $c) => $c->amount, $charges)), 2);

        return DB::transaction(function () use ($subscription, $cycleKey, $charges, $total) {
            $id = Id::make('pf');
            // Supersede any prior ACTIVE pro forma for this subscription, recording WHICH
            // pro forma replaced it (the supersede chain) rather than just the status.
            DB::table('pro_forma')->where('subscription_id', $subscription->subscription_id)
                ->where('status', 'ACTIVE')->update(['status' => 'SUPERSEDED', 'superseded_by' => $id, 'updated_at' => now()]);

            DB::table('pro_forma')->insert([
                'pro_forma_id' => $id,
                'operator_code' => $subscription->operator_code,
                'subscription_id' => $subscription->subscription_id,
                'customer_id' => $subscription->customer_id,
                'currency' => $subscription->currency ?? 'KES',
                'total_amount' => $total,
                'lines' => json_encode(array_map(fn (Charge $c) => [
                    'serviceCategory' => $c->serviceCategoryCode, 'amount' => $c->amount, 'quantity' => $c->quantity,
                ], $charges)),
                'customer_snapshot' => $subscription->customer_id ? json_encode($this->snapshots->captureSnapshot($subscription->customer_id, $subscription->account_id)) : null,
                'cycle_end' => $subscription->current_cycle_end,
                'idempotency_cycle_key' => $cycleKey,
                'status' => 'ACTIVE',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('subscription')->where('subscription_id', $subscription->subscription_id)
                ->update(['next_cycle_charge_invoice_id' => $id]); // pro forma ref on the master

            return true;
        });
    }
}
