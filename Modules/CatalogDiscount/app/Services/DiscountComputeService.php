<?php

namespace Modules\Catalog\Discount\Services;

use Carbon\Carbon;
use Modules\Catalog\Discount\Models\Discount;
use Modules\Catalog\Discount\Models\DiscountAssignment;
use Modules\Catalog\Discount\Models\PromoCampaign;

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
            ->where('status', DiscountAssignment::ACTIVE)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $at->copy()->endOfDay()))
            ->where(fn ($q) => $q->whereNull('valid_to')->orWhere('valid_to', '>=', $at->copy()->startOfDay()))
            ->where(function ($q) use ($scopeRefs) {
                $q->where('scope', 'ALL');
                foreach ($scopeRefs as $scope => $ref) {
                    $q->orWhere(fn ($w) => $w->where('scope', $scope)->where('scope_ref', $ref));
                }
            })
            ->get();

        // Option B: a CAMPAIGN-mode assignment only applies while its campaign is live
        // (status ACTIVE and within starts_at/ends_at). DIRECT assignments are unaffected.
        // This is the gate that prevents "active but not applying" surprises.
        $campaignIds = $assignments->where('assignment_mode', 'CAMPAIGN')->pluck('campaign_id')->filter()->unique();
        $liveCampaigns = $campaignIds->isEmpty() ? collect() : PromoCampaign::query()
            ->whereIn('campaign_id', $campaignIds->all())
            ->where('status', PromoCampaign::ACTIVE)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at))
            ->pluck('campaign_id')->flip();

        $assignments = $assignments->filter(function ($a) use ($liveCampaigns) {
            if (($a->assignment_mode ?? 'DIRECT') !== 'CAMPAIGN') {
                return true; // DIRECT applies on its own validity
            }

            return $a->campaign_id && $liveCampaigns->has($a->campaign_id);
        });

        // R-SIP-DA-06 / DIS-OP-01 stacking groups: within a non-null stacking_group_code only the
        // highest-priority assignment survives (lower assignment_priority = higher precedence);
        // on a priority tie a DIRECT (manual) grant wins over a CAMPAIGN one.
        $assignments = $assignments
            ->sortBy(fn ($a) => (($a->assignment_priority ?? 100) * 2) + ((($a->assignment_mode ?? 'DIRECT') === 'DIRECT') ? 0 : 1))
            ->groupBy(fn ($a) => $a->stacking_group_code ?: '__ungrouped__'.$a->assignment_id)
            ->map(fn ($group) => $group->first())
            ->values();

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
