<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01 discrepancy — a mismatch between expected and observed, routed to an action (DD §5.5). */
class FieldAuditDiscrepancy extends Model
{
    use HasPrefixedId;

    public const OPEN = 'OPEN';
    public const ROUTED = 'ROUTED';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const ACTION_CREATED = 'ACTION_CREATED';
    public const RESOLVED = 'RESOLVED';
    public const REJECTED = 'REJECTED';
    public const CLOSED = 'CLOSED';

    protected $table = 'field_audit_discrepancy';
    protected $primaryKey = 'discrepancy_id';
    protected string $idPrefix = 'fad';
    protected $guarded = [];
    protected $casts = ['resolved_at' => 'datetime'];

    public function getRouteKeyName(): string { return 'discrepancy_id'; }
    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }
}
