<?php

namespace Database\Seeders\Kenya;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Wananchi Kenya billing behaviour — CONFIGURATION (BIL-02-GEN-01). Sets the WIK
 * cycle-invoice grouping to WALLET so the dual-wallet model produces one invoice
 * per wallet (Internet/TV vs Voice) exactly as the Confluence Recurring Billing
 * page describes — money in one wallet cannot fund the other. One-off invoices
 * stay SINGLE. Pure data; operators retune without code.
 *
 * (The 28 anniversary cycles, the Day-25 pro forma window and the 30-day proration
 *  basis are configured per-subscription via cycle_model=ANNIVERSARY /
 *  cycle_anchor_day / cycle_period_days=30 — see KenyaSampleCustomersSeeder.)
 */
class KenyaBillingConfigSeeder extends Seeder
{
    public function run(): void
    {
        $policy = [
            'CYCLE_POSTPAID' => 'WALLET',   // dual-wallet → one invoice per wallet
            'CYCLE_PREPAID' => 'WALLET',
            'ONE_OFF_INVOICE' => 'SINGLE',
        ];
        foreach ($policy as $trigger => $dimension) {
            DB::table('invoice_grouping_config')->updateOrInsert(
                ['operator_code' => 'WIK', 'trigger_code' => $trigger],
                ['grouping_dimension' => $dimension, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
