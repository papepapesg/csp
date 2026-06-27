<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * WO-01 job-type catalog row (WO-01-FLOW-SUPPORT §3.2). Drives the
 * site-visit-decision gateway (requires_site_visit) and warranty linkage
 * (warranty_days). Per-operator, per-kind.
 *
 * @property bool $requires_site_visit
 * @property int $warranty_days
 */
class WoJobTypeCatalog extends Model
{
    use HasPrefixedId;

    protected $table = 'wo_job_type_catalog';

    protected string $idPrefix = 'wojt';

    protected $guarded = [];

    protected $casts = [
        'requires_site_visit' => 'boolean',
        'warranty_days' => 'integer',
    ];
}
