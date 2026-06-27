<?php

namespace Modules\Catalog\Network\Models;

use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 region↔contractor assignment with per-region skill scope. */
class TechRegionContractor extends Model
{
    protected $table = 'tech_region_contractor';
    public $incrementing = false;
    protected $primaryKey = null;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['skills' => 'array'];
}
