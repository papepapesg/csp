<?php

namespace Modules\Catalog\Services;

use App\Foundation\Rules\RuleEngine;
use Carbon\Carbon;
use Modules\Catalog\Models\TaxGroup;
use Modules\Catalog\Models\TaxRule;

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

        // R-PLM-02-AP-1: resolve the tax group (operator-scoped applicability).
        $decision = $this->rules->evaluate('rules.tax-applicability', [
            'operatorCode' => $operator,
            'taxableKind' => $request['taxableKind'] ?? null,
            'taxableRef' => $request['taxableRef'] ?? null,
            'customerCategory' => $request['customerCategory'] ?? 'RESIDENTIAL',
        ]);
        $groupCode = $decision['taxGroup'] ?? null;

        // R-PLM-02-AP-2/3: no group / explicit NONE -> zero tax, not an error.
        if (! $groupCode || $groupCode === 'NONE') {
            return [
                'taxLines' => [], 'totalTaxAmount' => 0.0, 'totalWithTax' => round($baseAmount, 2),
                'currency' => $currency, 'resolutionStatus' => $groupCode === 'NONE' ? 'EXEMPT_BY_RULE' : 'NO_TAX_GROUP_RESOLVED', 'taxGroup' => null,
            ];
        }

        $group = TaxGroup::query()->where('operator_code', $operator)->where('code', $groupCode)->first();
        if (! $group) {
            return [
                'taxLines' => [], 'totalTaxAmount' => 0.0, 'totalWithTax' => round($baseAmount, 2),
                'currency' => $currency, 'resolutionStatus' => 'NO_TAX_GROUP_RESOLVED', 'taxGroup' => null,
            ];
        }

        $taxLines = [];
        $runningTotal = 0.0; // cumulative tax for BASE_PLUS_PRIOR cascade
        foreach ($group->order_within_group as $ruleCode) {
            $rule = TaxRule::query()->where('operator_code', $operator)->where('code', $ruleCode)
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $taxableAt))
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $taxableAt))
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
}
