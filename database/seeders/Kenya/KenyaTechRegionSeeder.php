<?php

namespace Database\Seeders\Kenya;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Models\TechRegion;

/**
 * Wananchi Kenya network topology — CONFIGURATION (RLM-CFG-01). Seeds the Kenya
 * tech-region hierarchy (Country → City → Neighbourhood) and a starter set of
 * SERVICEABLE Home Passes (mixed GPON + HFC, per the HFC+GPON AS-IS), so the
 * commercial teams have sellable footprint on day one. Idempotent.
 */
class KenyaTechRegionSeeder extends Seeder
{
    private const OP = 'WIK';

    public function run(): void
    {
        // [id, parent, name, type]
        $regions = [
            ['KE', null, 'Kenya', 'COUNTRY'],
            ['KE-NRB', 'KE', 'Nairobi', 'CITY'],
            ['KE-NRB-KAREN', 'KE-NRB', 'Karen', 'NEIGHBORHOOD'],
            ['KE-NRB-WESTLANDS', 'KE-NRB', 'Westlands', 'NEIGHBORHOOD'],
            ['KE-NRB-KILIMANI', 'KE-NRB', 'Kilimani', 'NEIGHBORHOOD'],
            ['KE-NRB-KASARANI', 'KE-NRB', 'Kasarani', 'NEIGHBORHOOD'],
            ['KE-MSA', 'KE', 'Mombasa', 'CITY'],
            ['KE-KSM', 'KE', 'Kisumu', 'CITY'],
            ['KE-NKR', 'KE', 'Nakuru', 'CITY'],
        ];
        foreach ($regions as [$id, $parent, $name, $type]) {
            TechRegion::query()->updateOrCreate(
                ['tech_region_id' => $id],
                ['operator_code' => self::OP, 'parent_region_id' => $parent, 'display_name_primary' => $name,
                    'region_type' => $type, 'active' => true],
            );
        }

        // [id, code, address, regionId, technology]  — all SERVICEABLE (sellable+active)
        $homePasses = [
            ['hp_ke_0001', 'NRB-KAREN-0001', 'Karen Road, House 12, Nairobi', 'KE-NRB-KAREN', 'GPON'],
            ['hp_ke_0002', 'NRB-KAREN-0002', 'Marula Lane, House 4, Nairobi', 'KE-NRB-KAREN', 'GPON'],
            ['hp_ke_0003', 'NRB-WEST-0001', 'Westlands Ave, Apt 6B, Nairobi', 'KE-NRB-WESTLANDS', 'GPON'],
            ['hp_ke_0004', 'NRB-WEST-0002', 'Rhapta Road, Apt 2A, Nairobi', 'KE-NRB-WESTLANDS', 'HFC'],
            ['hp_ke_0005', 'NRB-KILI-0001', 'Kirichwa Road, Apt 9, Nairobi', 'KE-NRB-KILIMANI', 'GPON'],
            ['hp_ke_0006', 'NRB-KILI-0002', 'Argwings Kodhek, Apt 3C, Nairobi', 'KE-NRB-KILIMANI', 'HFC'],
            ['hp_ke_0007', 'NRB-KAS-0001', 'Thika Road, House 21, Nairobi', 'KE-NRB-KASARANI', 'GPON'],
            ['hp_ke_0008', 'MSA-0001', 'Nyali Road, House 8, Mombasa', 'KE-MSA', 'GPON'],
            ['hp_ke_0009', 'KSM-0001', 'Oginga Odinga St, Apt 5, Kisumu', 'KE-KSM', 'HFC'],
            ['hp_ke_0010', 'NKR-0001', 'Kenyatta Ave, House 14, Nakuru', 'KE-NKR', 'GPON'],
        ];
        foreach ($homePasses as [$id, $code, $address, $regionId, $tech]) {
            HomePass::query()->updateOrCreate(
                ['id' => $id],
                ['operator_code' => self::OP, 'code' => $code, 'address' => $address, 'tech_region_id' => $regionId,
                    'technology' => $tech, 'status' => 'SERVICEABLE', 'has_been_active' => false],
            );
            DB::table('homepass_tech_region')->updateOrInsert(
                ['homepass_id' => $id, 'tech_region_ref' => $regionId],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }
}
