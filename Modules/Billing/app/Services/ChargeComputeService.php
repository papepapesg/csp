<?php

namespace Modules\Billing\Services;

use Illuminate\Support\Collection;
use Modules\Billing\Models\RatedEvent;
use Modules\Catalog\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;

/**
 * BIL-01 Charging Engine — computes "what is owed". This is the single place
 * that turns a subscription + cycle window into a typed list of Charges, keeping
 * the two billing models distinct:
 *
 *   - RECURRING — the fixed periodic package fee (subscription/rental).
 *   - USAGE     — metered consumption, one charge per service category (VOICE /
 *                 DATA / SMS …), summed from the cycle's unbilled rated events.
 *
 * BIL-03 (boundary) and BIL-02-GEN-01 (invoice assembly) consume this; neither
 * computes amounts itself.
 */
class ChargeComputeService
{
    /**
     * Charges for a subscription's closing cycle + the rated-event ids consumed
     * (so the caller can flag them billed once the invoice commits).
     *
     * @return array{charges: array<int,Charge>, ratedIds: array<int,string>}
     */
    public function cycleCharges(Subscription $subscription): array
    {
        $charges = [];

        // Recurring package fee — service_category SUBSCRIPTION (the DD's
        // discriminator from usage; it is not a separate charge_type).
        $fee = $this->recurringFee($subscription);
        if ($fee > 0) {
            $charges[] = new Charge(
                serviceCategoryCode: 'SUBSCRIPTION',
                descriptionKey: 'billing.charge.recurring',
                amount: $fee,
                packageRef: $subscription->package_ref,
                walletTypeCode: $subscription->default_wallet_ref ?? null,
            );
        }

        // Usage — one charge per service category (VOICE/DATA/SMS), summed from
        // the cycle's rated events.
        $rated = RatedEvent::query()
            ->where('subscription_id', $subscription->subscription_id)
            ->where('billed', false)->get();

        foreach ($this->groupUsage($rated) as $category => $info) {
            $charges[] = new Charge(
                serviceCategoryCode: $category,
                descriptionKey: 'billing.charge.usage.'.strtolower($category),
                amount: round($info['amount'], 2),
                quantity: $info['count'],
                packageRef: $subscription->package_ref,
            );
        }

        return ['charges' => $charges, 'ratedIds' => $rated->pluck('rated_id')->all()];
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
        }

        return $buckets;
    }

    private function recurringFee(Subscription $subscription): float
    {
        if (! $subscription->package_version_id) {
            return 0.0;
        }

        return (float) (PackageVersion::query()->whereKey($subscription->package_version_id)->value('price') ?? 0);
    }
}
