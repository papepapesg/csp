<?php

namespace Modules\Billing\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Rules\Models\DecisionTable;

/**
 * BIL-02-ADJ-01 operator governance: the reason-code catalog every adjustment
 * must reference, the per-operator limits, and the approval ROUTING decision
 * table (rules.billing.adjustment-approval) — approval is a rules-engine
 * decision, operator-overridable from the Rules studio without code.
 */
class AdjustmentConfigSeeder extends Seeder
{
    private const REASONS = [
        ['code' => 'DISPUTE_RESOLVED', 'description' => 'Dispute resolved in customer\'s favor', 'direction' => 'CREDIT'],
        ['code' => 'BILLING_ERROR_CORRECTION', 'description' => 'Correction of a billing error', 'direction' => 'ANY'],
        ['code' => 'SLA_COMPENSATION', 'description' => 'SLA-driven service credit', 'direction' => 'CREDIT'],
        ['code' => 'GOODWILL_CREDIT', 'description' => 'Goodwill / retention gesture', 'direction' => 'CREDIT'],
        ['code' => 'UNDER_BILLING_CORRECTION', 'description' => 'Charge omitted from the original invoice', 'direction' => 'DEBIT'],
        ['code' => 'LATE_FEE', 'description' => 'Late fee added to a paid invoice', 'direction' => 'DEBIT'],
        ['code' => 'OVER_CREDIT_RECOVERY', 'description' => 'Recovery of a previously over-issued credit', 'direction' => 'DEBIT'],
    ];

    public function run(): void
    {
        foreach (['WIK', 'WUG', 'WTZ', 'YASSN'] as $operator) {
            foreach (self::REASONS as $reason) {
                DB::table('adjustment_reason_code')->updateOrInsert(
                    ['operator_code' => $operator, 'code' => $reason['code']],
                    $reason + ['active' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }

            // Limits keep an agent from issuing unbounded credits without an
            // override; steps/threshold remain the FALLBACK approval policy when
            // no decision table is deployed for the rule set.
            DB::table('adjustment_limits_config')->updateOrInsert(
                ['operator_code' => $operator],
                [
                    'max_per_request' => 50000,
                    'max_per_customer_period' => 100000,
                    'period_days' => 30,
                    'approval_steps_required' => 1,
                    'auto_approve_under' => 500,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        // Approval routing as a GLOBAL decision table (FIRST hit). Operators
        // override by deploying their own operator-scoped version of the same
        // rule set from the Rules studio — no code change.
        DecisionTable::query()->updateOrCreate(
            ['rule_set' => 'rules.billing.adjustment-approval', 'version' => 1, 'operator_code' => null],
            [
                'table_id' => Id::make('dt'),
                'name' => 'Adjustment approval routing (ADJ-01)',
                'hit_policy' => 'FIRST',
                'inputs' => ['direction', 'scope', 'amount', 'currency', 'reasonCode', 'billingMode', 'limitBreached'],
                'rules' => [
                    // A limit breach (after override) always escalates to dual control.
                    ['ruleId' => 'R-ADJ-APPR-1', 'when' => [['var' => 'limitBreached', 'op' => 'truthy']],
                        'then' => ['stepsRequired' => 2, 'decisionCode' => 'LIMIT_ESCALATION']],
                    // Debit notes (we take money) always need a human, however small.
                    ['ruleId' => 'R-ADJ-APPR-2', 'when' => [['var' => 'direction', 'op' => 'eq', 'value' => 'DEBIT']],
                        'then' => ['stepsRequired' => 1, 'decisionCode' => 'DEBIT_STANDARD']],
                    // Small credits flow without friction.
                    ['ruleId' => 'R-ADJ-APPR-3', 'when' => [['var' => 'amount', 'op' => 'lt', 'value' => 500]],
                        'then' => ['stepsRequired' => 0, 'decisionCode' => 'AUTO_SMALL_CREDIT']],
                    // Large credits need dual control.
                    ['ruleId' => 'R-ADJ-APPR-4', 'when' => [['var' => 'amount', 'op' => 'gte', 'value' => 20000]],
                        'then' => ['stepsRequired' => 2, 'decisionCode' => 'DUAL_CONTROL']],
                ],
                'default_output' => ['stepsRequired' => 1, 'decisionCode' => 'SINGLE_APPROVAL'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
