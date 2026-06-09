<?php

namespace Modules\Provisioning\Database\Seeders;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Provisioning\Adapters\StubProvisioningAdapter;
use Modules\Provisioning\Models\ProvisioningAdapterConfig;
use Modules\Provisioning\Models\ProvisioningTarget;

/**
 * Seeds default provisioning targets (NMS platforms) for WIK and, per PROV-INT-01
 * §10.2, an adapter binding for each. The seed points every target at the stub
 * driver so flows run with no hardware; a real deployment swaps adapter_class to the
 * vendor class (e.g. a Huawei NCE GPON adapter, a SIP adapter for VOIP).
 */
class ProvisioningTargetSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['target_code' => 'HUAWEI_NCE_GPON_KE', 'type' => 'GPON', 'name' => 'Huawei NCE GPON (Kenya)'],
            ['target_code' => 'CMTS_HFC_KE', 'type' => 'HFC', 'name' => 'CMTS HFC (Kenya)'],
            ['target_code' => 'SIP_VOICE_KE', 'type' => 'VOIP', 'name' => 'SIP Voice Platform (Kenya)'],
            ['target_code' => 'DEFAULT_NMS', 'type' => 'NMS', 'name' => 'Default NMS'],
        ] as $t) {
            ProvisioningTarget::query()->updateOrCreate(
                ['target_code' => $t['target_code']],
                $t + ['operator_code' => 'WIK', 'active' => true],
            );

            // Default per-target adapter binding (real deployments replace adapter_class).
            ProvisioningAdapterConfig::query()->updateOrCreate(
                ['operator_code' => 'WIK', 'target_code' => $t['target_code']],
                ['adapter_config_id' => Id::make('pac'), 'adapter_class' => StubProvisioningAdapter::class, 'status' => 'ACTIVE'],
            );
        }
    }
}
