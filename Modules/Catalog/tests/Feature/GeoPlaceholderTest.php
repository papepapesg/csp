<?php

namespace Modules\Catalog\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Models\TechRegion;
use Tests\TestCase;

/**
 * Geo placeholders accept externally-produced GIS data (a tech_region boundary
 * polygon, a homepass point) and round-trip it as decoded GeoJSON — the import
 * surface for CGIS data rendered on a map.
 */
class GeoPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_tech_region_stores_an_imported_boundary(): void
    {
        $boundary = [
            'type' => 'Polygon',
            'coordinates' => [[[-17.47, 14.71], [-17.45, 14.71], [-17.45, 14.73], [-17.47, 14.73], [-17.47, 14.71]]],
        ];

        TechRegion::query()->create([
            'tech_region_id' => 'SN-DKR-NORTH',
            'operator_code' => 'WIK',
            'display_name_primary' => 'Dakar North',
            'region_type' => 'CITY',
            'geo_boundary' => $boundary,
            'geo_centroid_lat' => 14.7200000,
            'geo_centroid_lng' => -17.4600000,
            'geo_source' => 'operator-GIS-2026',
            'geo_imported_at' => now(),
        ]);

        $region = TechRegion::query()->find('SN-DKR-NORTH');
        $this->assertSame('Polygon', $region->geo_boundary['type']);          // decoded back to array
        $this->assertEqualsWithDelta(14.72, $region->geo_centroid_lat, 0.0001);
        $this->assertSame('operator-GIS-2026', $region->geo_source);
    }

    public function test_homepass_stores_imported_coordinates(): void
    {
        $hp = HomePass::query()->create([
            'operator_code' => 'WIK',
            'address' => '12 Rue des Almadies, Dakar',
            'tech_region_id' => 'SN-DKR-NORTH',
            'geo_lat' => 14.7430000,
            'geo_lng' => -17.5210000,
            'geo_source' => 'survey-import',
            'geo_imported_at' => now(),
        ]);

        $fresh = HomePass::query()->find($hp->id);
        $this->assertEqualsWithDelta(14.743, $fresh->geo_lat, 0.0001);
        $this->assertEqualsWithDelta(-17.521, $fresh->geo_lng, 0.0001);
        $this->assertNull($fresh->geo_footprint);                              // optional, unset
    }
}
