<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\RatedEvent;
use Modules\Catalog\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-01 Charging Engine — computes "what is owed". This is the single place
 * that turns a subscription + cycle window into a typed list of Charges, keeping
 * the two billing models distinct (triple-play scenario):
 *
 *   - The CYCLICAL package fee (service_category SUBSCRIPTION) — Internet + TV
 *     bill together here because the catalog prices the PACKAGE, not its
 *     components (package_version.price; package_service carries no per-component
 *     price). Wallet routing comes from the package's default_wallet_ref.
 *   - USAGE — metered consumption, one charge per service category (VOICE /
 *     DATA / SMS), summed from the cycle's unbilled rated events. Wallet routing
 *     comes from the package's USAGE-model service whose revenue_category
 *     matches (PLM-CFG-01 service.default_wallet_ref) — so a WALLET grouping
 *     policy can put voice on its own invoice while Internet+TV share one.
 *
 * BIL-03 (boundary) and BIL-02-GEN-01 (invoice assembly) consume this; neither
 * computes amounts itself.
 */
class ChargeComputeService
{
    /**
     * Charges for a subscription's closing cycle + the consumed rated-event ids
     * BY service category — RAT-01 mark-invoiced needs "the invoice id and the
     * list of rated usage ids", and a grouping split means different categories
     * may land on different invoices.
     *
     * @return array{charges: array<int,Charge>, ratedIdsByCategory: array<string,array<int,string>>}
     */
    public function cycleCharges(Subscription $subscription): array
    {
        $charges = [];
        $package = $this->packageRow($subscription);

        // Cyclical package fee: Internet+TV together (priced as the package).
        $fee = $this->recurringFee($subscription);
        if ($fee > 0) {
            $charges[] = new Charge(
                serviceCategoryCode: 'SUBSCRIPTION',
                descriptionKey: 'billing.charge.recurring',
                amount: $fee,
                packageRef: $subscription->package_ref,
                walletTypeCode: $package->default_wallet_ref ?? null,
            );
        }

        // Usage — one charge per service category, summed from rated events.
        $rated = RatedEvent::query()
            ->where('subscription_id', $subscription->subscription_id)
            ->where('billed', false)->get();

        $ratedIdsByCategory = [];
        foreach ($this->groupUsage($rated) as $category => $info) {
            $ratedIdsByCategory[$category] = $info['ids'];
            $charges[] = new Charge(
                serviceCategoryCode: $category,
                descriptionKey: 'billing.charge.usage.'.strtolower($category),
                amount: round($info['amount'], 2),
                quantity: $info['count'],
                packageRef: $subscription->package_ref,
                walletTypeCode: $this->usageWalletRef($subscription, $category),
            );
        }

        return ['charges' => $charges, 'ratedIdsByCategory' => $ratedIdsByCategory];
    }

    /** Map rated events to a service category (VOICE/DATA/SMS) via tariff_code. */
    private function groupUsage(Collection $rated): array
    {
        $buckets = [];
        foreach ($rated as $e) {
            $category = match (true) {
                str_starts_with((string) $e->tariff_code, 'DATA') => 'DATA',
                str_starts_with((string) $e->tariff_code, 'SMS') => 'SMS',
                default => 'VOICE',
            };
            $buckets[$category]['amount'] = ($buckets[$category]['amount'] ?? 0) + (float) $e->amount;
            $buckets[$category]['count'] = ($buckets[$category]['count'] ?? 0) + 1;
            $buckets[$category]['ids'][] = $e->rated_id;
        }

        return $buckets;
    }

    /**
     * PLM-CFG-01: the wallet ref of the package's USAGE-model service whose
     * revenue_category matches the usage category (e.g. the VOICE service of a
     * triple-play package). Null when the package has no such component.
     */
    private function usageWalletRef(Subscription $subscription, string $category): ?string
    {
        return DB::table('package_service')
            ->join('service', 'service.id', '=', 'package_service.service_id')
            ->where('package_service.package_id', $subscription->package_ref)
            ->where('service.consumption_model', 'USAGE')
            ->where('service.revenue_category', $category)
            ->value('service.default_wallet_ref');
    }

    private function packageRow(Subscription $subscription): ?object
    {
        return $subscription->package_ref
            ? DB::table('package')->where('id', $subscription->package_ref)->first()
            : null;
    }

    private function recurringFee(Subscription $subscription): float
    {
        if (! $subscription->package_version_id) {
            return 0.0;
        }
        $price = (float) (PackageVersion::query()->whereKey($subscription->package_version_id)->value('price') ?? 0);

        // Proration: a partial cycle (the first calendar-aligned cycle, or a cycle
        // cut short) is charged pro-rata by days. A full cycle factors to 1.0.
        $start = $subscription->current_cycle_start;
        $end = $subscription->current_cycle_end;
        if ($price > 0 && $start && $end) {
            // Whole-day counts (Carbon 3 diffInDays is fractional): a cycle that is
            // a full period long factors to 1.0; only a genuinely shorter cycle prorates.
            $nominalDays = (int) round((float) $start->diffInDays((clone $start)->add($subscription->cyclePeriod())));
            $actualDays = (int) round((float) $start->diffInDays($end));
            if ($nominalDays > 0 && $actualDays < $nominalDays) {
                $price = round($price * ($actualDays / $nominalDays), 2);
            }
        }

        return $price;
    }
}
