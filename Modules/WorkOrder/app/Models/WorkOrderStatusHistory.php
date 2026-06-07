<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** WO-01 append-only status history. */
class WorkOrderStatusHistory extends Model
{
    use HasPrefixedId;

    protected $table = 'wo_status_history';

    protected string $idPrefix = 'wosh';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];
}
