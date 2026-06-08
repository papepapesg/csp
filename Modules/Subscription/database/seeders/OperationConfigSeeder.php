<?php

namespace Modules\Subscription\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Subscription\Models\SubscriptionOperationConfig;

/**
 * Seeds SUB-WF-FRAMEWORK-01 §6.3 per-operator/per-kind operation config: the BPMN
 * process key + timeouts the framework resolves at trigger time (R-SUB-WF-FW-7).
 */
class OperationConfigSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // operation_kind => process key
        $map = [
            'ACTIVATE' => 'sub-activate',
            'PAUSE' => 'sub-pause',
            'RESUME' => 'sub-resume',
            'SUSPEND_NP' => 'sub-suspend',
            'UPGRADE' => 'sub-upgrade',
            'DOWNGRADE' => 'sub-downgrade',
            'RELOCATION' => 'sub-relocation',
            'MIGRATION' => 'sub-migration',
            'RESTRICT' => 'sub-restrict',
            'TERMINATE' => 'sub-terminate',
        ];

        foreach ($map as $kind => $key) {
            SubscriptionOperationConfig::query()->updateOrCreate(
                ['operator_code' => $operator, 'operation_kind' => $kind],
                [
                    'default_bpmn_process_key' => $key,
                    'operation_timeout_seconds' => 120,
                    'billing_call_timeout_seconds' => 30,
                    'fulfillment_call_timeout_seconds' => 60,
                    'enabled' => true,
                    'updated_by' => 'seed',
                ],
            );
        }
    }
}
