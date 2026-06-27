<?php

namespace Modules\Catalog\Tax\Services;

use App\Foundation\Rules\RuleEngine;
use Carbon\Carbon;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\Service;
use Modules\Catalog\Tax\Models\TaxGroup;
use Modules\Catalog\Tax\Models\TaxRule;

/**
 * PLM-CFG-02 stateless tax compute service. Resolves the applicable tax_group via
 * the rules.tax-applicability decision table, iterates the group's rules in
 * order_within_group, computes the base per base_method (BASE | BASE_PLUS_PRIOR),
 * applies the rate with rounding, and returns the tax-line breakdown + totals.
 * No persistence — BIL-01 stores the result (R-PLM-02-CO-2).
 */
class TaxComputeService
{
    public function __construct(private readonly RuleEngine $rules) {}

    /**
     * @param  array<string,mixed>  $request  operatorCode, taxableKind, taxableRef, baseAmount, currency, customerCategory, taxableAt
     * @return array{taxLines:array<int,array<string,mixed>>, totalTaxAmount:float, totalWithTax:float, currency:string, resolutionStatus:string, taxGroup:?string}
     */
    public function compute(array $request): array
    {
        $operator = $request['operatorCode'];
        $baseAmount = (float) $request['baseAmount'];
        $currency = $request['currency'] ?? 'KES';
        if ($baseAmount < 0) {
            // R-PLM-02-CO-4: negative base is the credit-note path, not supported here.
            throw new \InvalidArgumentException('NEGATIVE_BASE_NOT_SUPPORTED');
        }
        $taxableAt = isset($request['taxableAt']) ? Carbon::parse($request['taxableAt']) : now();

        // The taxable product's own default_tax_group_ref is the DEFAULT tax group; the
        // applicability rule is the OVERRIDE layer (e.g. business customer / exempt region).
        // We expose the default as a fact (so a rule may reference it) and fall back to it
        // when the rule resolves nothing — so the product's configured group is honoured.
        $productDefault = $this->productDefaultTaxGroup($operator, $request['taxableKind'] ?? null, $request['taxableRef'] ?? null);

        // R-PLM-02-AP-1: resolve the tax group (operator-scoped applicability).
        $decision = $this->rules->evaluate('rules.tax-applicability', [
            'operatorCode' => $operator,
            'taxableKind' => $request['taxableKind'] ?? null,
            'taxableRef' => $request['taxableRef'] ?? null,
            'customerCategory' => $request['customerCategory'] ?? 'RESIDENTIAL',
            'defaultTaxGroup' => $productDefault,
        ]);
        $ruleGroup = $decision['taxGroup'] ?? null;

        // R-PLM-02-AP-2/3: explicit NONE = exempt by rule (no fallback). Otherwise the rule
        // result wins, else the product default; absent both = no tax (not an error).
        if ($ruleGroup === 'NONE') {
            return $this->untaxed($baseAmount, $currency, 'EXEMPT_BY_RULE');
        }
        $groupCode = $ruleGroup ?: $productDefault;
        if (! $groupCode) {
            return $this->untaxed($baseAmount, $currency, 'NO_TAX_GROUP_RESOLVED');
        }

        $group = TaxGroup::query()->where('operator_code', $operator)
            ->where(fn ($q) => $q->where('code', $groupCode)->orWhere('tax_group_id', $groupCode))
            ->first();
        if (! $group) {
            return $this->untaxed($baseAmount, $currency, 'NO_TAX_GROUP_RESOLVED');
        }

        $taxLines = [];
        $runningTotal = 0.0; // cumulative tax for BASE_PLUS_PRIOR cascade
        foreach ($group->order_within_group as $ruleCode) {
            $rule = TaxRule::query()->where('operator_code', $operator)->where('code', $ruleCode)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $taxableAt))
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $taxableAt))
                ->orderByDesc('effective_from') // newest applicable version wins (R-PLM-02-CT-1)
                ->first();
            if (! $rule) {
                continue;
            }

            $base = $rule->base_method === 'BASE_PLUS_PRIOR' ? $baseAmount + $runningTotal : $baseAmount;
            $taxAmount = round($base * (float) $rule->rate, $rule->rounding_scale);
            $runningTotal += $taxAmount;

            $taxLines[] = [
                'code' => $rule->code, 'rate' => (float) $rule->rate, 'baseMethod' => $rule->base_method,
                'baseAmount' => round($base, 2), 'taxAmount' => $taxAmount, 'regulatorTaxCode' => $rule->regulator_tax_code,
            ];
        }

        return [
            'taxLines' => $taxLines,
            'totalTaxAmount' => round($runningTotal, 2),
            'totalWithTax' => round($baseAmount + $runningTotal, 2),
            'currency' => $currency,
            'resolutionStatus' => 'RESOLVED',
            'taxGroup' => $groupCode,
        ];
    }

    /** The taxable product's configured default tax group (code or id), if any. */
    private function productDefaultTaxGroup(string $operator, ?string $kind, ?string $ref): ?string
    {
        if (! $ref) {
            return null;
        }
        $model = match ($kind) {
            'PACKAGE' => Package::query()->where('operator_code', $operator)->where(fn ($q) => $q->where('id', $ref)->orWhere('code', $ref))->first(),
            'SERVICE' => Service::query()->where('operator_code', $operator)->where(fn ($q) => $q->where('id', $ref)->orWhere('code', $ref))->first(),
            default => null,
        };

        return $model?->default_tax_group_ref;
    }

    /**
     * @return array{taxLines:array<int,mixed>, totalTaxAmount:float, totalWithTax:float, currency:string, resolutionStatus:string, taxGroup:?string}
     */
    private function untaxed(float $baseAmount, string $currency, string $status): array
    {
        return [
            'taxLines' => [], 'totalTaxAmount' => 0.0, 'totalWithTax' => round($baseAmount, 2),
            'currency' => $currency, 'resolutionStatus' => $status, 'taxGroup' => null,
        ];
    }
}
