<?php

namespace Modules\Billing\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the BIL-04 escalation policy as a decision table (rules.billing.dunning).
 * Grace periods + actions per level are configuration; operators override per
 * market in the Rules Studio. FIRST hit policy.
 */
class DunningPolicySeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.billing.dunning', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Dunning escalation policy',
                'hit_policy' => 'FIRST',
                'inputs' => ['currentLevel', 'daysOverdue', 'daysAtLevel', 'outstandingBalance'],
                'rules' => [
                    ['ruleId' => 'R-BIL-DUN-001', 'when' => [['var' => 'currentLevel', 'op' => 'eq', 'value' => 0], ['var' => 'daysOverdue', 'op' => 'gte', 'value' => 7]], 'then' => ['nextLevel' => 1, 'action' => 'WARN']],
                    ['ruleId' => 'R-BIL-DUN-002', 'when' => [['var' => 'currentLevel', 'op' => 'eq', 'value' => 1], ['var' => 'daysAtLevel', 'op' => 'gte', 'value' => 7]], 'then' => ['nextLevel' => 2, 'action' => 'RESTRICT', 'restrictionCode' => 'OUTGOING_VOICE_BARRED']],
                    ['ruleId' => 'R-BIL-DUN-003', 'when' => [['var' => 'currentLevel', 'op' => 'eq', 'value' => 2], ['var' => 'daysAtLevel', 'op' => 'gte', 'value' => 7]], 'then' => ['nextLevel' => 3, 'action' => 'SUSPEND']],
                    ['ruleId' => 'R-BIL-DUN-004', 'when' => [['var' => 'currentLevel', 'op' => 'eq', 'value' => 3], ['var' => 'daysAtLevel', 'op' => 'gte', 'value' => 14]], 'then' => ['nextLevel' => 4, 'action' => 'TERMINATE']],
                ],
                'default_output' => [],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
