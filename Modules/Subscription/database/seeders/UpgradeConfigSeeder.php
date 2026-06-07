<?php

namespace Modules\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscription\Models\SubscriptionUpgradeConfig;

/**
 * Seeds the SUB-WF-UPGRADE-01 / DOWNGRADE-01 per-operator config (WIK sample:
 * self-service enabled, pay-first, PRESERVE anchor, IMMEDIATE timing).
 */
class UpgradeConfigSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');
        foreach (['UPGRADE', 'DOWNGRADE'] as $kind) {
            SubscriptionUpgradeConfig::query()->updateOrCreate(
                ['operator_code' => $operator, 'kind' => $kind],
                [
                    'customer_self_service_enabled' => true,
                    'pay_first_required' => true,
                    'default_cycle_anchor_policy' => 'PRESERVE',
                    'default_effective_timing' => 'IMMEDIATE',
                    'max_future_scheduled_days' => 90,
                    'customer_notification_enabled' => true,
                    'updated_by' => 'seed',
                ],
            );
        }
    }
}
