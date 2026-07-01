<?php

namespace Modules\Billing\Database\Seeders;

use App\Foundation\Approvals\ApprovalDefinition;
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
    // direction + the GL posting account per direction (finance reconciliation).
    private const REASONS = [
        ['code' => 'DISPUTE_RESOLVED', 'description' => 'Dispute resolved in customer\'s favor', 'direction' => 'CREDIT', 'credit_gl_code' => '4000-REVENUE-ADJ-CR', 'debit_gl_code' => null],
        ['code' => 'BILLING_ERROR_CORRECTION', 'description' => 'Correction of a billing error', 'direction' => 'ANY', 'credit_gl_code' => '4010-BILLING-ERR-CR', 'debit_gl_code' => '4011-BILLING-ERR-DR'],
        ['code' => 'SLA_COMPENSATION', 'description' => 'SLA-driven service credit', 'direction' => 'CREDIT', 'credit_gl_code' => '5200-SLA-COMPENSATION', 'debit_gl_code' => null],
        ['code' => 'GOODWILL_CREDIT', 'description' => 'Goodwill / retention gesture', 'direction' => 'CREDIT', 'credit_gl_code' => '5210-GOODWILL', 'debit_gl_code' => null],
        ['code' => 'UNDER_BILLING_CORRECTION', 'description' => 'Charge omitted from the original invoice', 'direction' => 'DEBIT', 'credit_gl_code' => null, 'debit_gl_code' => '4100-UNDERBILL-RECOVERY'],
        ['code' => 'LATE_FEE', 'description' => 'Late fee added to a paid invoice', 'direction' => 'DEBIT', 'credit_gl_code' => null, 'debit_gl_code' => '4200-LATE-FEE-REVENUE'],
        ['code' => 'OVER_CREDIT_RECOVERY', 'description' => 'Recovery of a previously over-issued credit', 'direction' => 'DEBIT', 'credit_gl_code' => null, 'debit_gl_code' => '4300-OVER-CREDIT-RECOVERY'],
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

            // EM-CFG-04 "config on the process row": the operator's adjustment guard-rails live on
            // the BASE ADJUSTMENT process (action=null) — no separate global table. Limits keep an
            // agent from issuing unbounded credits without an override.
            ApprovalDefinition::defineChain($operator, 'ADJUSTMENT', null, [], [
                'config' => [
                    'max_per_request' => 50000,
                    'max_per_customer_period' => 100000,
                    'period_days' => 30,
                    'auto_approve_under' => 500,
                ],
            ]);

            // Pre-authored approval PROCESSES (tiers) the rules engine selects between. Roles are
            // open — route permission (adjustment.approve) gates WHO; the engine enforces the
            // distinct-approver quorum.
            ApprovalDefinition::defineChain($operator, 'ADJUSTMENT', 'SINGLE', [
                ['name' => 'Adjustment approval', 'approver_kind' => 'ROLE', 'approver_roles' => [], 'required_approvals' => 1],
            ]);
            ApprovalDefinition::defineChain($operator, 'ADJUSTMENT', 'DUAL', [
                ['name' => 'Adjustment dual control', 'approver_kind' => 'ROLE', 'approver_roles' => [], 'required_approvals' => 2],
            ]);
            // AUTO is a real process too — no local bypass. It carries no stages; the engine records the
            // request as AUTO_APPROVED (config.auto_approve) so EVERY adjustment passes through EM-CFG-04.
            ApprovalDefinition::defineChain($operator, 'ADJUSTMENT', 'AUTO', [], [
                'config' => ['auto_approve' => true],
            ]);

            // GEN-01 rule group R — bulk reversal dual control. Definition-first, like every other gate:
            // one approval by someone OTHER than the proposer (allow_requester=false is the SoD anchor).
            // The service no longer hand-rolls this chain inline; it resolves this process by entity_type.
            ApprovalDefinition::defineChain($operator, 'BULK_REVERSAL', null, [
                ['name' => 'Bulk reversal approval', 'approver_kind' => 'ROLE', 'approver_roles' => [], 'required_approvals' => 1, 'allow_requester' => false],
            ]);
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
                        'then' => ['stepsRequired' => 2, 'approvalProcess' => 'DUAL', 'decisionCode' => 'LIMIT_ESCALATION']],
                    // Debit notes (we take money) always need a human, however small.
                    ['ruleId' => 'R-ADJ-APPR-2', 'when' => [['var' => 'direction', 'op' => 'eq', 'value' => 'DEBIT']],
                        'then' => ['stepsRequired' => 1, 'approvalProcess' => 'SINGLE', 'decisionCode' => 'DEBIT_STANDARD']],
                    // Small credits flow without friction.
                    ['ruleId' => 'R-ADJ-APPR-3', 'when' => [['var' => 'amount', 'op' => 'lt', 'value' => 500]],
                        'then' => ['stepsRequired' => 0, 'approvalProcess' => 'AUTO', 'decisionCode' => 'AUTO_SMALL_CREDIT']],
                    // Large credits need dual control.
                    ['ruleId' => 'R-ADJ-APPR-4', 'when' => [['var' => 'amount', 'op' => 'gte', 'value' => 20000]],
                        'then' => ['stepsRequired' => 2, 'approvalProcess' => 'DUAL', 'decisionCode' => 'DUAL_CONTROL']],
                ],
                'default_output' => ['stepsRequired' => 1, 'approvalProcess' => 'SINGLE', 'decisionCode' => 'SINGLE_APPROVAL'],
                'status' => DecisionTable::DEPLOYED,
            ],
        );
    }
}
