<?php

namespace Modules\Ilm\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Ilm\Models\CustomerAccountFlagCatalog;
use Modules\Ilm\Models\CustomerSubStatusCatalog;

/** ILM-CFG-01 §3.5 WIK account flag catalog + sub-status registry seed. */
class AccountFlagCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // [flag_code, name, value_kind, evaluator, surfaces_attention]
        $flags = [
            ['NPD', 'No Payment Done (cash-only)', 'BOOLEAN', 'DROOLS', true],
            ['CHURN_RISK', 'Churn risk score', 'SCORE_0_100', 'DROOLS', false],
            ['FRAUD_SUSPECTED', 'Fraud suspected', 'BOOLEAN', 'MANUAL', true],
            ['PAYMENT_DELAY_FREQUENT', 'Frequent payment delays', 'BOOLEAN', 'DROOLS', false],
            ['LOYALTY_TIER', 'Loyalty tier', 'TIER', 'EVENT_DRIVEN', false],
            ['HIGH_VALUE', 'High-value account', 'BOOLEAN', 'DROOLS', true],
        ];
        foreach ($flags as [$code, $name, $kind, $eval, $attn]) {
            CustomerAccountFlagCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'flag_code' => $code],
                ['name' => $name, 'value_kind' => $kind, 'evaluator' => $eval, 'surfaces_attention' => $attn, 'active' => true],
            );
        }

        // [sub_status_code, main_status, display]
        $subStatuses = [
            ['active', 'ACTIVE', 'Active'],
            ['complementary_discount', 'ACTIVE', 'Complementary discount'],
            ['dnd_active', 'ACTIVE', 'Do-not-disturb active'],
            ['seasonal_disconnect', 'INACTIVE', 'Seasonal disconnect'],
            ['vip', 'ACTIVE', 'VIP'],
            ['staff_account', 'ACTIVE', 'Staff account'],
            ['inactive_pending_customer', 'INACTIVE', 'Inactive pending customer'],
            ['relocating_awaiting_construction', 'INACTIVE', 'Relocating, awaiting construction'],
            ['churned', 'INACTIVE', 'Churned'],
            ['awaiting_equipment_collection', 'INACTIVE', 'Awaiting equipment collection'],
            ['new', 'INACTIVE', 'New'],
        ];
        foreach ($subStatuses as [$code, $main, $display]) {
            CustomerSubStatusCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'sub_status_code' => $code],
                ['main_status' => $main, 'display_name' => $display, 'active' => true],
            );
        }
    }
}
