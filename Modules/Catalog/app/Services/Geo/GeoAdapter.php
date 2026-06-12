<?php

namespace Modules\Catalog\Services\Geo;

/** RLM-CFG-01 §GIS — pluggable geocoding adapter (Google default; swappable per deployment). */
interface GeoAdapter
{
    /** @return array{country?:string,region_l1?:string,city?:string,area?:string,road_name?:string,google_place_id?:string}|null */
    public function reverseGeocode(float $latitude, float $longitude): ?array;
}
