<?php

namespace Modules\Catalog\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Catalog\Tax\Models\TaxGroup;
use Modules\Catalog\Tax\Models\TaxRule;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the PLM-CFG-02 tax catalog for WIK: Internet group cascading EXCISE (15%,
 * BASE) then VAT (16%, BASE_PLUS_PRIOR), plus the applicability rule that resolves
 * INTERNET charges to that group.
 */
class TaxCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');

        TaxRule::query()->updateOrCreate(
            ['operator_code' => $op, 'code' => 'WIK_INTERNET_EXCISE'],
            ['tax_rule_id' => Id::make('txr'), 'name' => 'Internet Excise', 'taxable_category' => 'INTERNET',
                'rate' => 0.15, 'base_method' => 'BASE', 'order_within_group' => 1, 'regulator' => 'KRA', 'regulator_tax_code' => 'EXCISE'],
        );
        TaxRule::query()->updateOrCreate(
            ['operator_code' => $op, 'code' => 'WIK_INTERNET_VAT'],
            ['tax_rule_id' => Id::make('txr'), 'name' => 'Internet VAT', 'taxable_category' => 'INTERNET',
                'rate' => 0.16, 'base_method' => 'BASE_PLUS_PRIOR', 'order_within_group' => 2, 'regulator' => 'KRA', 'regulator_tax_code' => 'VAT'],
        );

        TaxGroup::query()->updateOrCreate(
            ['operator_code' => $op, 'code' => 'WIK_INTERNET'],
            ['tax_group_id' => Id::make('txg'), 'name' => 'Internet taxes', 'order_within_group' => ['WIK_INTERNET_EXCISE', 'WIK_INTERNET_VAT'], 'regulator_reference' => 'KRA-WIK'],
        );

        // rules.tax-applicability — resolve INTERNET charges to the WIK_INTERNET group.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.tax-applicability', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Tax group applicability',
                'hit_policy' => 'FIRST',
                'inputs' => ['operatorCode', 'taxableKind', 'taxableRef', 'customerCategory'],
                'rules' => [
                    ['ruleId' => 'R-TAX-APP-001', 'when' => [['var' => 'taxableKind', 'op' => 'in', 'value' => ['PACKAGE', 'INTERNET']]], 'then' => ['taxGroup' => 'WIK_INTERNET']],
                ],
                'default_output' => ['taxGroup' => null],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
