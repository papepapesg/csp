<?php

namespace Modules\Osr\Swap\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * OSR-RMA-01 swap-request — the workflow root for an equipment swap / pickup /
 * upgrade. Per-flow specifics live in flow_payload.
 *
 * @property string $swap_id
 * @property string $status
 */
class EquipmentSwapRequest extends Model
{
    use HasPrefixedId;

    public const CREATED = 'CREATED';

    public const AWAITING_SLOT = 'AWAITING_SLOT';

    public const WO_CREATED = 'WO_CREATED';

    public const FIELD_VISIT_IN_PROGRESS = 'FIELD_VISIT_IN_PROGRESS';

    public const SOURCE_RECOVERED = 'SOURCE_RECOVERED';

    public const COMPLETED = 'COMPLETED';

    public const COMPLETED_WITHOUT_RECOVERY = 'COMPLETED_WITHOUT_RECOVERY';

    public const FAILED = 'FAILED';

    protected $table = 'equipment_swap_request';

    protected $primaryKey = 'swap_id';

    protected string $idPrefix = 'swp';

    protected $guarded = [];

    protected $casts = ['chargeable' => 'boolean', 'flow_payload' => 'array', 'charge_amount' => 'decimal:2'];

    public function getRouteKeyName(): string
    {
        return 'swap_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
