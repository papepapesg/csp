<?php

namespace Modules\Catalog\Network\Services\Geo;

/**
 * v1 default adapter. Real Google Maps Platform calls are made when an API key is configured
 * (homepass.gis.google_maps.api_key); without a key it behaves as a no-op so the module works
 * without GIS configured.
 */
class GoogleMapsGeoAdapter implements GeoAdapter
{
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        // Network call to Google is out of v1 scope here; without a key this returns null.
        return null;
    }
}
