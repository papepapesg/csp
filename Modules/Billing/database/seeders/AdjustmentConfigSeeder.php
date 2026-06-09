<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-02-ADJ-01 operator governance: the reason-code catalog every adjustment
 * must reference, and the per-operator limits / approval policy.
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

            // Single-step approval; small goodwill credits auto-approve; caps keep
            // an agent from issuing unbounded credits without an override.
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
    }
}
