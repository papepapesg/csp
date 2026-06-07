<?php

namespace Modules\Ticketing\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/** Seeds rules.asr.routing — queue + auto-actions per ASR type. */
class AsrPolicySeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.asr.routing', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'ASR routing',
                'hit_policy' => 'FIRST',
                'inputs' => ['asrType'],
                'rules' => [
                    ['ruleId' => 'R-ASR-RT-001', 'when' => [['var' => 'asrType', 'op' => 'eq', 'value' => 'TECHNICAL_TROUBLE']], 'then' => ['queue' => 'NOC', 'priority' => 'HIGH', 'autoCreateWorkOrder' => true]],
                    ['ruleId' => 'R-ASR-RT-002', 'when' => [['var' => 'asrType', 'op' => 'eq', 'value' => 'COMPLAINT']], 'then' => ['queue' => 'QUALITY', 'priority' => 'HIGH']],
                    ['ruleId' => 'R-ASR-RT-003', 'when' => [['var' => 'asrType', 'op' => 'eq', 'value' => 'SERVICE_REQUEST']], 'then' => ['queue' => 'FULFILLMENT', 'priority' => 'NORMAL']],
                ],
                'default_output' => ['queue' => 'GENERAL', 'priority' => 'NORMAL'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
