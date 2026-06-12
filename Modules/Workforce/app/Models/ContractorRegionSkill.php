<?php

namespace Modules\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-02 §3.4 contractor-level skill certification per region (the WO routing filter). */
class ContractorRegionSkill extends Model
{
    protected $table = 'contractor_region_skill';

    public $incrementing = false;

    protected $primaryKey = null;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];
}
