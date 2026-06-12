<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01/02/03 audit campaign — a planned group of field-audit tasks (DD §5.1). */
class FieldAuditCampaign extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';
    public const SCHEDULED = 'SCHEDULED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const RECONCILING = 'RECONCILING';
    public const CLOSED = 'CLOSED';
    public const CANCELLED = 'CANCELLED';

    protected $table = 'field_audit_campaign';
    protected $primaryKey = 'campaign_id';
    protected string $idPrefix = 'fac';
    protected $guarded = [];
    protected $casts = ['scheduled_start_at' => 'datetime', 'scheduled_end_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getRouteKeyName(): string { return 'campaign_id'; }
    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }
}
