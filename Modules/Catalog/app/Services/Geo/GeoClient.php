<?php

namespace Modules\Catalog\Services\Geo;

use Modules\Catalog\Models\HomePass;

/**
 * RLM-CFG-01 §GIS façade. Reads homepass.gis.* config at construction: when GIS is disabled
 * (default), the NoOp adapter is used and enrich-from-geo silently degrades. The adapter pattern
 * lets a deployment swap Google for another provider without touching the module.
 */
class GeoClient
{
    private readonly GeoAdapter $adapter;

    public function __construct()
    {
        $enabled = (bool) config('sophix.homepass.gis.enabled', false);
        $this->adapter = $enabled ? new GoogleMapsGeoAdapter() : new NoOpGeoAdapter();
    }

    public function enabled(): bool
    {
        return ! ($this->adapter instanceof NoOpGeoAdapter);
    }

    /**
     * R-RLM-CFG-01-H-17/H-18: reverse-geocode the HomePass coordinates and fill any empty admin
     * fields + google_place_id (immutable once set, so only filled when null). Degrades to a no-op
     * when GIS is disabled or the coordinates are missing.
     */
    public function enrichFromGeo(HomePass $homepass): HomePass
    {
        if (! $this->enabled() || $homepass->latitude === null || $homepass->longitude === null) {
            return $homepass;
        }
        $geo = $this->adapter->reverseGeocode((float) $homepass->latitude, (float) $homepass->longitude);
        if (! $geo) {
            return $homepass;
        }
        $patch = [];
        foreach (['country', 'region_l1', 'city', 'area', 'road_name'] as $field) {
            if (empty($homepass->{$field}) && ! empty($geo[$field])) {
                $patch[$field] = $geo[$field];
            }
        }
        if ($homepass->google_place_id === null && ! empty($geo['google_place_id'])) {
            $patch['google_place_id'] = $geo['google_place_id'];
        }
        if ($patch !== []) {
            $homepass->update($patch);
        }

        return $homepass->refresh();
    }
}
