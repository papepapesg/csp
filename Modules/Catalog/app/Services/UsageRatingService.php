<?php

namespace Modules\Catalog\Services;

use App\Foundation\Support\Context;
use Modules\Catalog\Models\UsageTariff;

/**
 * Generic, service-type-agnostic usage rating (DATA / SMS / and any future metered
 * service). One engine, parameterised by the usage_tariff row: reservation (initial
 * increment) + pulse (subsequent increment) rounding on ANY unit, allowance burn-down,
 * per-unit price + setup fee floored at the minimum charge, and the charge policy.
 *
 * The math is identical to voice's rateCall — voice keeps its own resolver (zone-by-
 * prefix); this covers everything that resolves a tariff by (operator, usage_type).
 * Stateless: the caller (mediation/Billing) owns the allowance balance + carry-over.
 */
class UsageRatingService
{
    /**
     * @param  array<string,mixed>  $event  operatorCode?, usageType, quantity, remainingAllowanceUnits?
     * @return array<string,mixed>  resolved=false when no tariff exists (caller may fall back)
     */
    public function rate(array $event): array
    {
        $operator = $event['operatorCode'] ?? Context::operatorCode();
        $usageType = $event['usageType'];
        $quantity = max(0.0, (float) ($event['quantity'] ?? 0));
        $remainingAllowance = max(0.0, (float) ($event['remainingAllowanceUnits'] ?? 0));

        $tariff = UsageTariff::query()->where('operator_code', $operator)
            ->where('usage_type', $usageType)->where('active', true)->first();
        if (! $tariff) {
            return ['resolved' => false];
        }

        $policy = $tariff->charge_policy ?? 'CHARGEABLE';
        $billable = $this->reservePulse(
            $quantity,
            ((float) ($tariff->initial_increment_units ?? 1)) ?: 1.0,
            ((float) ($tariff->subsequent_increment_units ?? 1)) ?: 1.0,
        );

        if ($policy === 'BLOCKED') {
            return $this->result($tariff, 'BLOCKED', 0, 0, 0, 0.0);
        }
        if ($policy === 'ZERO_RATED') {
            return $this->result($tariff, 'ZERO_RATED', $billable, 0, 0, 0.0); // free: no allowance burn, no charge
        }

        $allowanceUsed = min($billable, $remainingAllowance);
        $chargeable = $billable - $allowanceUsed;
        $unitPrice = (float) $tariff->rate_per_unit;
        $amount = $chargeable * $unitPrice;
        if ($chargeable > 0) {
            $amount += (float) ($tariff->setup_fee ?? 0);
            $amount = max($amount, (float) ($tariff->min_charge ?? 0));
        }

        return $this->result($tariff, 'CHARGED', $billable, $allowanceUsed, $chargeable, round($amount, 4));
    }

    /** Round usage up by the reservation (first block) then pulse (subsequent blocks). */
    private function reservePulse(float $quantity, float $initial, float $pulse): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }
        if ($quantity <= $initial) {
            return $initial;
        }

        return $initial + ceil(($quantity - $initial) / $pulse) * $pulse;
    }

    /** @return array<string,mixed> */
    private function result(UsageTariff $tariff, string $chargeStatus, float $billable, float $allowanceUsed, float $chargeable, float $amount): array
    {
        return [
            'resolved' => true,
            'chargeStatus' => $chargeStatus,                       // CHARGED | ZERO_RATED | BLOCKED
            'usageType' => $tariff->usage_type,
            'tariffCode' => strtoupper((string) $tariff->usage_type).'_TARIFF',
            'unitType' => $tariff->unit_type ?? $tariff->unit,
            'rate' => (float) $tariff->rate_per_unit,
            'billableUnits' => $billable,
            'allowanceConsumedUnits' => $allowanceUsed,
            'chargeableUnits' => $chargeable,
            'amount' => $amount,
        ];
    }
}
