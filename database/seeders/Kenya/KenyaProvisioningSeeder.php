<?php

namespace Database\Seeders\Kenya;

use App\Foundation\Support\Id;
use Illuminate\Database\Seeder;
use Modules\Provisioning\Adapters\StubProvisioningAdapter;
use Modules\Provisioning\Models\ProvisioningAdapterConfig;
use Modules\Provisioning\Models\ProvisioningTarget;

/**
 * Wananchi Kenya provisioning targets — CONFIGURATION (PROV-INT-01 §10.2). The
 * platform seeds GPON / HFC / SIP / DEFAULT targets; this adds the Verimatrix TV
 * head-end so the four AS-IS network planes (GPON OLT, HFC CMTS, Voice SIP, TV
 * Verimatrix) are all represented.
 *
 * Every target is bound to the STUB adapter on purpose: external connectors are
 * NOT part of the BSS. At country go-live each binding's adapter_class is swapped
 * for the real vendor adapter (Huawei NCE, Casa/Clearcable, VoipSwitch, Verimatrix)
 * with no platform change — the seam already exists. Idempotent.
 */
class KenyaProvisioningSeeder extends Seeder
{
    private const OP = 'WIK';

    public function run(): void
    {
        $targets = [
            ['target_code' => 'VERIMATRIX_TV_KE', 'type' => 'TV', 'name' => 'Verimatrix TV Head-End (Kenya)'],
        ];
        foreach ($targets as $t) {
            ProvisioningTarget::query()->updateOrCreate(
                ['target_code' => $t['target_code']],
                $t + ['operator_code' => self::OP, 'active' => true],
            );
            ProvisioningAdapterConfig::query()->updateOrCreate(
                ['operator_code' => self::OP, 'target_code' => $t['target_code']],
                ['adapter_config_id' => Id::make('pac'), 'adapter_class' => StubProvisioningAdapter::class, 'status' => 'ACTIVE'],
            );
        }
    }
}
