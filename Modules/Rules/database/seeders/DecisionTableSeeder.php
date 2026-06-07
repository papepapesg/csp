<?php

namespace Modules\Rules\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Rules\Models\DecisionTable;

/**
 * Seeds the SUB-WF rule packages named by the design (DD_SUB-WF-*):
 * `rules.subscription.<kind>` (operator scoping via operator_code, matching the
 * design's `rules.subscription.<kind>.<operator>`). Each rule carries a stable
 * ruleId (DROOLS-RES-1). These are evaluated at the operation's
 * validate-preconditions decision point. Operations not yet built
 * (pause/resume/upgrade/downgrade/restrict/suspend-np/relocation/migration) are
 * catalogued in docs/RULES.md and seeded as their flows land.
 */
class DecisionTableSeeder extends Seeder
{
    public function run(): void
    {
        // rules.subscription.activate — activation preconditions / eligibility
        // (SUB-WF-ACTIVATE-01: validate-preconditions -> {eligible}).
        $this->deploy('rules.subscription.activate', 'Subscription activation preconditions', 'FIRST', [
            ['ruleId' => 'R-SUB-ACT-001', 'when' => [['var' => 'outstandingBalance', 'op' => 'gt', 'value' => 0]],
                'then' => ['eligible' => false, 'decisionCode' => 'PAY_FIRST_REQUIRED',
                    'error' => ['field' => 'outstandingBalance', 'message' => 'Outstanding balance must be cleared before activation']]],
        ], ['eligible' => true], ['statusCode', 'outstandingBalance', 'packageStatus', 'homepassStatus', 'role']);

        $this->deploy('rules.subscription.pause', 'Subscription pause policy', 'FIRST',
            [], ['eligible' => true], ['statusCode', 'reasonCode']);

        $this->deploy('rules.subscription.resume', 'Subscription resume policy', 'FIRST',
            [], ['eligible' => true], ['statusCode']);

        $this->deploy('rules.subscription.suspend-np', 'Non-payment suspension policy', 'FIRST',
            [], ['eligible' => true], ['statusCode', 'outstandingBalance']);

        // rules.subscription.terminate — termination policy (default: allowed).
        $this->deploy('rules.subscription.terminate', 'Subscription termination policy', 'FIRST',
            [], ['eligible' => true], ['statusCode', 'reasonCode']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rules
     * @param  array<string,mixed>  $default
     * @param  array<int,string>  $inputs
     */
    private function deploy(string $ruleSet, string $name, string $hit, array $rules, array $default, array $inputs): void
    {
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => $ruleSet, 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => $name,
                'hit_policy' => $hit,
                'inputs' => $inputs,
                'rules' => $rules,
                'default_output' => $default,
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
