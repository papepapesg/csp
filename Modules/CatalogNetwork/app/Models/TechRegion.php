<?php

namespace Modules\Catalog\Network\Models;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * ILM-CFG-02 tech region — serviceability geography (human-readable id, e.g.
 * KE-NRB-KAREN). Hierarchical via parent_region_id.
 */
class TechRegion extends Model
{
    protected $table = 'tech_region';

    protected $primaryKey = 'tech_region_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'effective_from' => 'date',
        'effective_to' => 'date',
        // Importable GIS placeholders (EPSG:4326): GeoJSON boundary + centroid.
        'geo_boundary' => 'array',
        'geo_centroid_lat' => 'float',
        'geo_centroid_lng' => 'float',
        'geo_imported_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'tech_region_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
