<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Billing\Models\DunningProgram;

/**
 * BIL-04 dunning program catalog (DD §worked examples). The authoritative operator policy
 * surface: each program's level_definitions drive grace periods + per-level actions. Seeds a
 * POSTPAID and a PREPAID program for the default operator plus a YAS_SN PREPAYMENT program —
 * all four levels (WARN → RESTRICT → SUSPEND → review-then-TERMINATE).
 */
class DunningPolicySeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');

        // Restriction codes reference SUB-LM-01's per-operator subscription_restriction catalog
        // (RestrictionCatalogSeeder). Wananchi's triple-play hits data + premium + voice together.
        $this->program("{$op}_postpaid_standard", $op, DunningProgram::POSTPAID,
            'Postpaid (data + TV + phone). 7-day warning, 7-day full-service restriction, 14-day suspension, 30-day pre-termination review.',
            [7, 7, 14, 30], ['DATA_THROTTLED_LOW', 'BLOCK_PREMIUM_CONTENT', 'OUTGOING_VOICE_BARRED'], 'DUNNING_GRACE_EXPIRED');

        $this->program("{$op}_prepaid_standard", $op, DunningProgram::PREPAID,
            'PREPAID wallet (data + TV + phone). Triggered on CyclePaymentMissed when wallet insufficient; auto-recovers on topup.',
            [3, 4, 14, 21], ['DATA_THROTTLED_LOW', 'BLOCK_PREMIUM_CONTENT', 'OUTGOING_VOICE_BARRED'], 'DUNNING_WALLET_INSUFFICIENT');

        $this->program('yas_sn_fiber_prepayment', 'YAS_SN', DunningProgram::PREPAYMENT,
            'Yas Senegal fiber data, pre-payment. Customer pays each cycle before it starts. Data-only restriction.',
            [5, 5, 21, 21], ['DATA_THROTTLED_LOW'], 'DUNNING_CYCLE_UNPAID');
    }

    /** @param array{0:int,1:int,2:int,3:int} $grace @param list<string> $restrictionCodes */
    private function program(string $code, string $operator, string $mode, string $desc, array $grace, array $restrictionCodes, string $suspendReason): void
    {
        $levels = [
            ['level' => 1, 'name' => 'WARNING', 'grace_period_days' => $grace[0], 'action_workflow_intent' => DunningProgram::WARNING_ONLY, 'action_payload' => (object) [], 'next_action_visibility_days' => $grace[0]],
            ['level' => 2, 'name' => 'RESTRICTED', 'grace_period_days' => $grace[1], 'action_workflow_intent' => DunningProgram::RESTRICTION_ADD, 'action_payload' => ['restriction_codes' => $restrictionCodes], 'next_action_visibility_days' => $grace[1]],
            ['level' => 3, 'name' => 'SUSPENDED', 'grace_period_days' => $grace[2], 'action_workflow_intent' => DunningProgram::SUSPEND_NP, 'action_payload' => ['reason_code' => $suspendReason], 'next_action_visibility_days' => $grace[2]],
            ['level' => 4, 'name' => 'TERMINATED', 'grace_period_days' => $grace[3], 'action_workflow_intent' => DunningProgram::TERMINATION, 'action_payload' => ['reason_code' => 'DUNNING_TERMINATION', 'equipment_disposition' => 'PENDING_COLLECTION']],
        ];

        DunningProgram::query()->updateOrCreate(
            ['code' => $code, 'version' => 1],
            [
                'description' => $desc, 'operator_code' => $operator, 'billing_mode' => $mode,
                'level_definitions' => $levels, 'pre_termination_review_required' => true,
                'published_at' => now(), 'created_by' => 'seed',
            ],
        );
    }
}
