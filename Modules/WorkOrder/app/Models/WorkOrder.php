<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * WO-01 work order — a field-execution work item (HLD §6.4).
 *
 * @property string $work_order_id
 * @property string $status
 */
class WorkOrder extends Model
{
    use HasPrefixedId;

    public const PENDING = 'PENDING';

    public const ASSIGNED = 'ASSIGNED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const FINALIZED = 'FINALIZED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'work_order';

    protected $primaryKey = 'work_order_id';

    protected string $idPrefix = 'wo';

    protected $guarded = [];

    protected $casts = [
        'findings' => 'array',
        'escalation_candidate' => 'boolean',
        'scheduled_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'finalized_at' => 'datetime',
        'warranty_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'work_order_id';
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(WorkOrderStatusHistory::class, 'work_order_id', 'work_order_id');
    }
}
