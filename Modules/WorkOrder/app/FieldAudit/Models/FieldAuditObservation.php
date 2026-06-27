<?php

namespace Modules\WorkOrder\FieldAudit\Models;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01 field observation — what the technician actually found (DD §5.4). */
class FieldAuditObservation extends Model
{
    use HasPrefixedId;

    protected $table = 'field_audit_observation';
    protected $primaryKey = 'observation_id';
    protected string $idPrefix = 'fao';
    protected $guarded = [];
    protected $casts = ['photo_file_ids_json' => 'array', 'gps_latitude' => 'decimal:6', 'gps_longitude' => 'decimal:6', 'captured_at' => 'datetime'];

    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }
}
