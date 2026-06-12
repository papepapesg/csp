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

        // [sub_status_code, main_status, requires_approval, affects_provisioning, customer_visible, display] (§3.1)
        $subStatuses = [
            ['active', 'ACTIVE', false, true, true, 'Active'],
            ['complementary_discount', 'ACTIVE', true, false, true, 'Complementary discount'],
            ['dnd_active', 'ACTIVE', false, false, false, 'Do-not-disturb active'],
            ['seasonal_disconnect', 'ACTIVE', false, true, true, 'Seasonal disconnect (travel)'],
            ['vip', 'ACTIVE', true, false, false, 'VIP'],
            ['staff_account', 'ACTIVE', true, false, false, 'Staff account'],
            ['inactive_pending_customer', 'INACTIVE', false, true, true, 'Inactive pending customer'],
            ['inactive_pending_vip', 'INACTIVE', true, true, false, 'Inactive pending VIP'],
            ['relocating_awaiting_construction', 'INACTIVE', false, false, true, 'Relocating, awaiting construction'],
            ['hold', 'INACTIVE', true, true, false, 'Hold'],
            ['forget_me', 'INACTIVE', true, true, false, 'Forget Me (GDPR)'],
            ['duplicate', 'INACTIVE', true, false, false, 'Duplicate'],
            ['no_disturb', 'INACTIVE', false, false, false, 'No Disturb'],
            ['disconnect_staff', 'INACTIVE', true, true, false, 'Staff Disconnect'],
            ['churned', 'INACTIVE', true, true, true, 'Churned'],
            ['awaiting_equipment_collection', 'INACTIVE', false, false, true, 'Awaiting equipment collection'],
            ['new', 'INACTIVE', false, false, true, 'New'],
        ];
        foreach ($subStatuses as [$code, $main, $reqApproval, $affectsProv, $custVisible, $display]) {
            CustomerSubStatusCatalog::query()->updateOrCreate(
                ['operator_code' => $operator, 'sub_status_code' => $code],
                ['main_status' => $main, 'requires_approval' => $reqApproval, 'affects_provisioning' => $affectsProv, 'customer_visible' => $custVisible, 'display_name' => $display, 'active' => true],
            );
        }

        // R-ILM-K-3: KYC approval authority per level (operator config). KE: L1 supervisor + Team Leader.
        foreach ([[1, 'L1_SUPERVISOR', 'CUSTOMER_CARE_SUPERVISOR'], [2, 'TEAM_LEADER', 'SUPER_ADMIN']] as [$lvl, $name, $role]) {
            \Illuminate\Support\Facades\DB::table('kyc_approval_role')->updateOrInsert(
                ['operator_code' => $operator, 'approval_level' => $lvl],
                ['level_name' => $name, 'required_role' => $role, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
