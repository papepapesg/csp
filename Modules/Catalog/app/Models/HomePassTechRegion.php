<?php

namespace Modules\Catalog\Models;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Models\TechRegion;

use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 HomePass↔TechRegion many-to-many link. */
class HomePassTechRegion extends Model
{
    protected $table = 'homepass_tech_region';
    public $incrementing = false;
    protected $primaryKey = null;
    protected $keyType = 'string';
    protected $guarded = [];
}
