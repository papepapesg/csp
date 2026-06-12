<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01 frozen expected equipment state (from OSR-INSTANCE) for an audit task (DD §5.3). */
class FieldAuditExpectedItem extends Model
{
    use HasPrefixedId;

    protected $table = 'field_audit_expected_item';
    protected $primaryKey = 'expected_item_id';
    protected string $idPrefix = 'fae';
    protected $guarded = [];
    protected $casts = ['source_snapshot_json' => 'array'];

    protected static function booted(): void { static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode()); }
}
