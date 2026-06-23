<?php

namespace Database\Seeders\Kenya;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Wananchi Kenya tax applicability — CONFIGURATION only. The platform already seeds
 * the KRA cascade (EXCISE 15% BASE → VAT 16% BASE_PLUS_PRIOR) as the WIK_INTERNET
 * tax group. This seeder publishes a WIK-scoped `rules.tax-applicability` decision
 * table that the engine prefers over the global default (operator-specific wins,
 * see DataDrivenRuleEngine::resolveTable), encoding two Confluence rules:
 *
 *   - tax IS charged on service value: SUBSCRIPTION / PACKAGE / INTERNET / TV → WIK_INTERNET
 *   - NO tax on usage: VOICE / DATA / SMS / USAGE → NONE (exempt by rule)
 *
 * Pure data — no platform code changes; tune in the Rules Studio at any time.
 */
class KenyaTaxSeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.tax-applicability', 'version' => 1, 'operator_code' => 'WIK'],
            [
                'table_id' => Id::make('dt'),
                'name' => 'WIK tax applicability (service taxed, usage exempt)',
                'hit_policy' => 'FIRST',
                'inputs' => ['operatorCode', 'taxableKind', 'taxableRef', 'customerCategory'],
                'rules' => [
                    [
                        'ruleId' => 'R-WIK-TAX-USAGE-EXEMPT',
                        'when' => [['var' => 'taxableKind', 'op' => 'in', 'value' => ['VOICE', 'DATA', 'SMS', 'USAGE']]],
                        'then' => ['taxGroup' => 'NONE'],
                    ],
                    [
                        'ruleId' => 'R-WIK-TAX-SERVICE',
                        'when' => [['var' => 'taxableKind', 'op' => 'in', 'value' => ['SUBSCRIPTION', 'PACKAGE', 'INTERNET', 'TV']]],
                        'then' => ['taxGroup' => 'WIK_INTERNET'],
                    ],
                ],
                'default_output' => ['taxGroup' => null],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
