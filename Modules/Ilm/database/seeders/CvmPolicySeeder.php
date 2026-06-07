<?php

namespace Modules\Ilm\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/** Seeds rules.cvm.offer — maps a CVM trigger to a retention/recovery offer. */
class CvmPolicySeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.cvm.offer', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'CVM offer resolution',
                'hit_policy' => 'FIRST',
                'inputs' => ['type', 'triggerReason', 'segment'],
                'rules' => [
                    ['ruleId' => 'R-CVM-OFR-001', 'when' => [['var' => 'triggerReason', 'op' => 'eq', 'value' => 'NON_PAYMENT']], 'then' => ['offerCode' => 'PAYMENT_PLAN_30D', 'offerDetails' => ['installments' => 3], 'validDays' => 7]],
                    ['ruleId' => 'R-CVM-OFR-002', 'when' => [['var' => 'type', 'op' => 'eq', 'value' => 'WINBACK']], 'then' => ['offerCode' => 'WINBACK_50OFF_3M', 'offerDetails' => ['discountPercent' => 50, 'months' => 3], 'validDays' => 30]],
                    ['ruleId' => 'R-CVM-OFR-003', 'when' => [['var' => 'type', 'op' => 'eq', 'value' => 'RETENTION']], 'then' => ['offerCode' => 'LOYALTY_FREE_MONTH', 'offerDetails' => ['freeMonths' => 1], 'validDays' => 14]],
                ],
                'default_output' => ['offerCode' => 'GOODWILL_CONTACT', 'offerDetails' => [], 'validDays' => 14],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
