<?php

namespace Modules\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-02 §3.2 per-region service-scope coverage. */
class ContractorRegionScope extends Model
{
    protected $table = 'contractor_region_scope';

    protected $primaryKey = 'coverage_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];
}
