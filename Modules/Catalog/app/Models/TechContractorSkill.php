<?php

namespace Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/** RLM-CFG-01 operator-extensible technical skill catalog. */
class TechContractorSkill extends Model
{
    protected $table = 'tech_contractor_skill';
    public $incrementing = false;
    protected $primaryKey = null;
    protected $keyType = 'string';
    protected $guarded = [];
}
