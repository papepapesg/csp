<?php

namespace Modules\Billing\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-02-GEN-01 operator grouping policy. KE WIK keeps cycle invoices as a
 * SINGLE document (lines grouped by package as SUMMARY/DETAIL); WUG illustrates
 * a per-PACKAGE split (one invoice per package). Operators tune this without code.
 */
class InvoiceGroupingSeeder extends Seeder
{
    private const POLICY = [
        'WIK' => ['CYCLE_POSTPAID' => 'SINGLE', 'ONE_OFF_INVOICE' => 'SINGLE'],
        'WUG' => ['CYCLE_POSTPAID' => 'PACKAGE', 'ONE_OFF_INVOICE' => 'SINGLE'],
        'WTZ' => ['CYCLE_POSTPAID' => 'SINGLE', 'ONE_OFF_INVOICE' => 'SINGLE'],
        'YASSN' => ['CYCLE_POSTPAID' => 'SINGLE', 'ONE_OFF_INVOICE' => 'SINGLE'],
    ];

    public function run(): void
    {
        foreach (self::POLICY as $operator => $triggers) {
            foreach ($triggers as $trigger => $dimension) {
                DB::table('invoice_grouping_config')->updateOrInsert(
                    ['operator_code' => $operator, 'trigger_code' => $trigger],
                    ['grouping_dimension' => $dimension, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }
}
