<?php

namespace Modules\Catalog\Services;

use Carbon\Carbon;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\DiscountAssignment;

/**
 * DIS-OP-01 discount runtime. Resolves the discounts assigned to a charge context
 * (customer / subscription / package / campaign / all), applies them in priority
 * order honouring stackability, and returns the discount-line breakdown + net.
 * The first non-stackable match wins alone; stackable discounts accumulate.
 */
class DiscountComputeService
{
    /**
     * @param  array<string,mixed>  $context  customerId?, subscriptionId?, packageRef?, campaignCode?
     * @return array{discountLines:array<int,array<string,mixed>>, totalDiscount:float, netAmount:float}
     */
    public function compute(string $operator, float $baseAmount, array $context = []): array
    {
        $at = isset($context['at']) ? Carbon::parse($context['at']) : now();

        $scopeRefs = array_filter([
            'CUSTOMER' => $context['customerId'] ?? null,
            'SUBSCRIPTION' => $context['subscriptionId'] ?? null,
            'PACKAGE' => $context['packageRef'] ?? null,
            'CAMPAIGN' => $context['campaignCode'] ?? null,
        ]);

        $assignments = DiscountAssignment::query()
            ->where('operator_code', $operator)
            ->where('active', true)
            ->where(function ($q) use ($scopeRefs) {
                $q->where('scope', 'ALL');
                foreach ($scopeRefs as $scope => $ref) {
                    $q->orWhere(fn ($w) => $w->where('scope', $scope)->where('scope_ref', $ref));
                }
            })
            ->get();

        $codes = $assignments->pluck('discount_code')->unique()->all();
        $discounts = Discount::query()
            ->where('operator_code', $operator)
            ->whereIn('code', $codes)
            ->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $at))
            ->orderBy('priority')
            ->get();

        $lines = [];
        $totalDiscount = 0.0;
        $remaining = $baseAmount;
        foreach ($discounts as $d) {
            $amount = $d->discount_type === 'PERCENT'
                ? round($baseAmount * (float) $d->value, 2)
                : min((float) $d->value, $remaining);
            $amount = min($amount, $remaining);
            if ($amount <= 0) {
                continue;
            }

            $lines[] = ['code' => $d->code, 'type' => $d->discount_type, 'value' => (float) $d->value, 'amount' => $amount];
            $totalDiscount += $amount;
            $remaining -= $amount;

            if (! $d->stackable) {
                break; // a non-stackable discount applies alone
            }
        }

        return [
            'discountLines' => $lines,
            'totalDiscount' => round($totalDiscount, 2),
            'netAmount' => round($baseAmount - $totalDiscount, 2),
        ];
    }
}
