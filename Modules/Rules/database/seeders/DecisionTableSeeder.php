<?php

namespace Modules\Rules\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds an example configurable policy: activation eligibility. The default
 * (global) policy blocks activation when there is an outstanding balance; an
 * operator can deploy a different table for the same rule_set to change the
 * policy with no code change.
 */
class DecisionTableSeeder extends Seeder
{
    public function run(): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'activation.eligibility', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Activation eligibility (default)',
                'hit_policy' => 'FIRST',
                'inputs' => ['outstandingBalance'],
                'rules' => [
                    ['when' => [['var' => 'outstandingBalance', 'op' => 'gt', 'value' => 0]], 'then' => ['eligible' => false, 'reason' => 'OUTSTANDING_BALANCE']],
                ],
                'default_output' => ['eligible' => true],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
