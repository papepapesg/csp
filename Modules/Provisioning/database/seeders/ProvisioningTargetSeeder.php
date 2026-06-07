<?php

namespace Modules\Provisioning\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Provisioning\Models\ProvisioningTarget;

/** Seeds default provisioning targets (NMS platforms) for WIK. */
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
        }
    }
}
