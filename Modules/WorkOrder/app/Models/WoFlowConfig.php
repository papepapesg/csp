<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * WO-01 flow config (WO-01-FLOW-SUPPORT §3.1): maps an operator + WO kind to the
 * engine process key. Swapping a market's flow is a config row, not code.
 */
class WoFlowConfig extends Model
{
    use HasPrefixedId;

    protected $table = 'wo_flow_config';

    protected string $idPrefix = 'woflow';

    protected $guarded = [];
}
