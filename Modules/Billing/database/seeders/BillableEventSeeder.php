<?php

namespace Modules\Billing\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * BIL-CFG-01 BillableEvent catalog seed. Categories give finance its revenue
 * streams; events declare what BIL-01 may charge and how each one fires. The
 * DD's KE seed is illustrative — codes/fees are deployment-specific — so each
 * operator gets the same baseline here, including the intent codes the seeded
 * SUB-WF flows emit (PRORATION / PAUSE_FEE / RECONNECTION_FEE / DEPOSIT_REFUND).
 */
class BillableEventSeeder extends Seeder
{
    private const CATEGORIES = [
        ['code' => 'LIFECYCLE_FEE', 'description' => 'Subscription lifecycle fees (pause, reactivation, package change, address move)', 'is_credit' => false],
        ['code' => 'EARLY_TERMINATION_FEE', 'description' => 'Early-termination fees', 'is_credit' => false],
        ['code' => 'INSTALLATION_FEE', 'description' => 'Installation and setup fees', 'is_credit' => false],
        ['code' => 'RECONNECTION_FEE', 'description' => 'Fees for resuming service after non-payment suspension', 'is_credit' => false],
        ['code' => 'PRORATION', 'description' => 'Proration deltas — partial-cycle charges or credits for cycle-anchor changes', 'is_credit' => false],
        ['code' => 'VAS', 'description' => 'Value-added services (PPV, on-demand content, premium support)', 'is_credit' => false],
        ['code' => 'REGULATORY', 'description' => 'Government-mandated regulatory fees and levies', 'is_credit' => false],
        ['code' => 'REFUND', 'description' => 'Customer refunds (cash, service credit, deposit return)', 'is_credit' => true],
        ['code' => 'SLA_COMPENSATION', 'description' => 'SLA-driven service credits issued to customers', 'is_credit' => true],
        ['code' => 'BUYBACK_CREDIT', 'description' => 'Hardware buyback credits issued to customers', 'is_credit' => true],
        ['code' => 'PROMOTIONAL_CREDIT', 'description' => 'One-shot promotional or welcome credits', 'is_credit' => true],
    ];

    /** code, category, trigger_type, intent code, sign policy, applicability, pay_first */
    private const EVENTS = [
        ['INSTALLATION_FEE', 'INSTALLATION_FEE', 'SAGA_INTENT', 'ACTIVATION_INTENT', 'POSITIVE_ONLY', 'ANY', true],
        ['PRORATION', 'PRORATION', 'SAGA_INTENT', 'PRORATION', 'SIGNED', 'ANY', true],
        ['PAUSE_FEE', 'LIFECYCLE_FEE', 'SAGA_INTENT', 'PAUSE_FEE', 'POSITIVE_ONLY', 'ANY', true],
        ['RECONNECTION_FEE', 'RECONNECTION_FEE', 'SAGA_INTENT', 'RECONNECTION_FEE', 'POSITIVE_ONLY', 'ANY', true],
        ['DEPOSIT_REFUND', 'REFUND', 'SAGA_INTENT', 'DEPOSIT_REFUND', 'NEGATIVE_ONLY', 'ANY', false],
        ['MIGRATION_FEE', 'LIFECYCLE_FEE', 'SAGA_INTENT', 'MIGRATION_INTENT', 'POSITIVE_ONLY', 'ANY', true],
        ['RELOCATION_FEE', 'LIFECYCLE_FEE', 'SAGA_INTENT', 'RELOCATION_INTENT', 'POSITIVE_ONLY', 'ANY', true],
        ['EARLY_TERMINATION_FEE', 'EARLY_TERMINATION_FEE', 'SAGA_INTENT', 'TERMINATION_INTENT', 'POSITIVE_ONLY', 'ANY', false],
        ['PREPAID_REFUND', 'REFUND', 'SAGA_INTENT', 'TERMINATION_INTENT', 'NEGATIVE_ONLY', 'PREPAID_ONLY', false],
        ['SLA_COMPENSATION_DOWNTIME', 'SLA_COMPENSATION', 'ADMIN_ACTION', null, 'NEGATIVE_ONLY', 'ANY', false],
        ['EQUIPMENT_BUYBACK_ONT', 'BUYBACK_CREDIT', 'ADMIN_ACTION', null, 'NEGATIVE_ONLY', 'ANY', false],
        ['PPV_MOVIE', 'VAS', 'CUSTOMER_PURCHASE', null, 'POSITIVE_ONLY', 'ANY', true],
        ['VAT_SURCHARGE', 'REGULATORY', 'SCHEDULED', null, 'POSITIVE_ONLY', 'ANY', false],
        ['RECONNECTION_FEE_AFTER_DUNNING', 'RECONNECTION_FEE', 'EXTERNAL_PAYMENT', null, 'POSITIVE_ONLY', 'POSTPAID_ONLY', true],
    ];

    public function run(): void
    {
        foreach (['WIK', 'WUG', 'WTZ', 'YASSN'] as $operator) {
            foreach (self::CATEGORIES as $i => $category) {
                DB::table('billable_event_category')->updateOrInsert(
                    ['operator_code' => $operator, 'code' => $category['code']],
                    $category + ['display_order' => $i * 10, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()],
                );
            }

            foreach (self::EVENTS as $i => [$code, $category, $triggerType, $intentCode, $signPolicy, $applicability, $payFirst]) {
                if (DB::table('billable_event')->where(['operator_code' => $operator, 'code' => $code])->exists()) {
                    continue; // keep the existing id stable on re-seed
                }
                DB::table('billable_event')->insert(
                    [
                        'operator_code' => $operator,
                        'code' => $code,
                        'id' => Id::make('bev'),
                        'description' => str_replace('_', ' ', ucfirst(strtolower($code))),
                        'category_code' => $category,
                        'service_refs' => json_encode([]),
                        'currency' => $operator === 'YASSN' ? 'XOF' : ($operator === 'WUG' ? 'UGX' : ($operator === 'WTZ' ? 'TZS' : 'KES')),
                        'applicability' => $applicability,
                        'amount_sign_policy' => $signPolicy,
                        'pay_first_required' => $payFirst,
                        'trigger_type' => $triggerType,
                        'trigger_intent_code' => $intentCode,
                        'trigger_schedule' => $triggerType === 'SCHEDULED' ? '0 0 1 * *' : null,
                        'display_order' => 100 + $i,
                        'status' => 'ACTIVE',
                        'created_by' => 'SEED',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }
    }
}
