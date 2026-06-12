<?php

namespace Modules\Catalog\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Catalog\Models\HomePassStatusCode;

/**
 * RLM-CFG-01 §1 default HomePass status catalog. A sane starting point any deployment
 * can extend — codes and their semantic flags are config, not code. Includes the legacy
 * codebase codes (DRAFT/SERVICEABLE/RESERVED/RETIRED) alongside the DD defaults.
 */
class HomePassStatusSeeder extends Seeder
{
    public function run(): void
    {
        $operator = config('sophix.default_operator', 'WIK');

        // [code, description, is_initial, is_sellable, is_active, is_terminal, requires_approval, triggers_lead, blocks_soft_delete]
        $codes = [
            ['NPL', 'Not Planned', true, false, false, false, false, false, false],
            ['NSN', 'Not Serviceable Network', false, false, false, false, false, false, false],
            ['RFS', 'Ready For Sales', false, true, false, false, true, true, false],
            ['WAI', 'Wired not Active', false, true, false, false, true, false, true],
            ['ACT', 'Active', false, false, true, false, true, false, true],
            ['RETIRED', 'Retired', false, false, false, true, true, false, false],
            // Legacy codebase codes mapped onto the same flag model.
            ['DRAFT', 'Draft', true, false, false, false, false, false, false],
            ['SERVICEABLE', 'Serviceable (sellable + active)', false, true, true, false, false, true, false],
            ['RESERVED', 'Reserved', false, false, false, false, false, false, false],
        ];
        foreach ($codes as [$code, $desc, $init, $sell, $active, $terminal, $appr, $lead, $blockDel]) {
            HomePassStatusCode::query()->updateOrCreate(
                ['operator_code' => $operator, 'code' => $code],
                ['description' => $desc, 'is_initial' => $init, 'is_sellable' => $sell, 'is_active' => $active,
                    'is_terminal' => $terminal, 'requires_approval_to_enter' => $appr, 'triggers_lead_notification' => $lead,
                    'blocks_soft_delete' => $blockDel, 'active' => true],
            );
        }
    }
}
