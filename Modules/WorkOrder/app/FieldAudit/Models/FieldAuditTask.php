<?php

namespace Modules\WorkOrder\FieldAudit\Models;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditDiscrepancy;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditExpectedItem;
use Modules\WorkOrder\FieldAudit\Models\FieldAuditObservation;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01/02/03 audit task — one field verification for a premises/site (DD §5.2). */
class FieldAuditTask extends Model
{
    use HasPrefixedId;

    public const CREATED = 'CREATED';
    public const ASSIGNED = 'ASSIGNED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const SUBMITTED = 'SUBMITTED';
    public const DISCREPANCY_OPEN = 'DISCREPANCY_OPEN';
    public const CLOSED = 'CLOSED';
    public const CANCELLED = 'CANCELLED';

    protected $table = 'field_audit_task';
    protected $primaryKey = 'audit_task_id';
    protected string $idPrefix = 'fat';
    protected $guarded = [];
    protected $casts = ['due_at' => 'datetime', 'submitted_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getRouteKeyName(): string { return 'audit_task_id'; }
    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }

    public function expectedItems() { return $this->hasMany(FieldAuditExpectedItem::class, 'audit_task_id', 'audit_task_id'); }
    public function observations() { return $this->hasMany(FieldAuditObservation::class, 'audit_task_id', 'audit_task_id'); }
    public function discrepancies() { return $this->hasMany(FieldAuditDiscrepancy::class, 'audit_task_id', 'audit_task_id'); }
}
