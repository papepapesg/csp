<?php

namespace Modules\Catalog\Network\Services\Geo;

/** Used when homepass.gis.enabled = false — every call silently returns null (degrade, never error). */
class NoOpGeoAdapter implements GeoAdapter
{
    public function reverseGeocode(float $latitude, float $longitude): ?array
    {
        return null;
    }
}
