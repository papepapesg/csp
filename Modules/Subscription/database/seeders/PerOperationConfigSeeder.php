<?php

namespace Modules\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscription\Models\SubscriptionPauseConfig;
use Modules\Subscription\Models\SubscriptionSuspendNpConfig;

/**
 * Seeds the per-operation operator config catalogs from the DD sample rows:
 *  - subscription_pause_config (PAUSE §7.3): WIK allows customer self-service;
 *    the other operators are conservative placeholders; all require >= 24h and
 *    allow scheduled pause up to 90 days.
 *  - subscription_suspend_np_config (SUSPEND-NP §3.1): all notify on suspension;
 *    WIK tiers HIGH-value churn risk at KES 5,000, others have no tiering.
 */
class PerOperationConfigSeeder extends Seeder
{
    public function run(): void
    {
        // operator => customer_self_service_enabled (rest of the columns use DD defaults).
        $pause = ['WIK' => true, 'WUG' => false, 'WTZ' => false, 'YASSN' => false];
        foreach ($pause as $operator => $selfService) {
            SubscriptionPauseConfig::query()->updateOrCreate(
                ['operator_code' => $operator],
                [
                    'customer_self_service_enabled' => $selfService,
                    'scheduled_pause_enabled' => true,
                    'max_future_scheduled_resume_days' => 90,
                    'min_pause_hours' => 24,
                    'customer_notification_enabled' => true,
                    'updated_by' => 'seed',
                ],
            );
        }

        // operator => [threshold, currency] (null,null = no debt tiering).
        $suspend = ['WIK' => [5000.00, 'KES'], 'WUG' => [null, null], 'WTZ' => [null, null], 'YASSN' => [null, null]];
        foreach ($suspend as $operator => [$threshold, $currency]) {
            SubscriptionSuspendNpConfig::query()->updateOrCreate(
                ['operator_code' => $operator],
                [
                    'customer_notification_enabled' => true,
                    'debt_amount_warning_threshold' => $threshold,
                    'debt_amount_warning_currency' => $currency,
                    'updated_by' => 'seed',
                ],
            );
        }
    }
}
