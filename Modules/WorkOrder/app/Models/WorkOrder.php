<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WoAssignmentHistory;
use Modules\WorkOrder\Models\WoAttachment;
use Modules\WorkOrder\Models\WoNote;
use Modules\WorkOrder\Models\WorkOrderStatusHistory;

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
    use \App\Foundation\Tenancy\BelongsToOperator;

    public const PENDING = 'PENDING';

    public const ASSIGNED = 'ASSIGNED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    // WO-01 §3 2-step finalize: first-confirm parks here, second-confirm completes.
    public const FINALIZATION_PENDING = 'FINALIZATION_PENDING';

    public const COMPLETED = 'COMPLETED';

    /** @deprecated DD terminal status is COMPLETED; kept as an alias for back-compat. */
    public const FINALIZED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'work_order';

    protected $primaryKey = 'work_order_id';

    protected string $idPrefix = 'wo';

    protected $guarded = [];

    protected $casts = [
        'findings' => 'array',
        'required_skills' => 'array',
        'escalation_candidate' => 'boolean',
        'scheduled_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'assigned_at' => 'datetime',
        'first_response_at' => 'datetime',
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

    public function notes(): HasMany
    {
        return $this->hasMany(WoNote::class, 'work_order_id', 'work_order_id');
    }

    public function assignmentHistory(): HasMany
    {
        return $this->hasMany(WoAssignmentHistory::class, 'work_order_id', 'work_order_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WoAttachment::class, 'work_order_id', 'work_order_id');
    }
}
