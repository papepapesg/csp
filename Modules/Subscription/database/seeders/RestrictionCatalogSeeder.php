<?php

namespace Modules\Subscription\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Subscription\Models\SubscriptionRestrictConfig;
use Modules\Subscription\Models\SubscriptionRestriction;

/**
 * Seeds the SUB-LM-01 restriction catalog + the SUB-WF-RESTRICT-01 per-operator
 * config for the default operator. Codes + fulfillment actions follow the DD
 * glossary (OUTGOING_VOICE_BARRED -> AAA_RESTRICT_OUTGOING_VOICE, etc.). Sample
 * config matches the DD's WIK row (self-service on, dunning-marker strict).
 */
class RestrictionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        $codes = [
            ['OUTGOING_VOICE_BARRED', 'Outgoing voice barred', 'AAA_RESTRICT_OUTGOING_VOICE', false, false],
            ['DATA_THROTTLED_LOW', 'Data throttled (low)', 'AAA_THROTTLE_DATA_LOW', true, false],
            ['BLOCK_PREMIUM_CONTENT', 'Block premium content', 'AAA_BLOCK_PREMIUM', true, false],
            ['FULL_SERVICE_SUSPENSION_PUNITIVE', 'Full service suspension (punitive)', 'AAA_SUSPEND_ALL', false, true],
        ];

        foreach ($codes as [$code, $name, $action, $selfService, $adminOnly]) {
            SubscriptionRestriction::query()->updateOrCreate(
                ['operator_code' => $operator, 'restriction_code' => $code],
                [
                    'restriction_id' => Id::make('srest'),
                    'name' => $name,
                    'fulfillment_action' => $action,
                    'customer_self_service_eligible' => $selfService,
                    'admin_only' => $adminOnly,
                    'is_active' => true,
                ],
            );
        }

        SubscriptionRestrictConfig::query()->updateOrCreate(
            ['operator_code' => $operator],
            [
                'customer_self_service_enabled' => true,
                'dunning_marker_strict' => true,
                'restriction_approval_required_for_codes' => [],
                'updated_by' => 'seed',
            ],
        );
    }
}
