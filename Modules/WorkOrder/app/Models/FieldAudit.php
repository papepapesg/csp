<?php

namespace Modules\WorkOrder\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** FA-01/02/03 field audit. */
class FieldAudit extends Model
{
    use HasPrefixedId;

    public const SCHEDULED = 'SCHEDULED';

    public const IN_FIELD = 'IN_FIELD';

    public const FINDINGS_SUBMITTED = 'FINDINGS_SUBMITTED';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const CLOSED = 'CLOSED';

    protected $table = 'field_audit';

    protected $primaryKey = 'audit_id';

    protected string $idPrefix = 'fa';

    protected $guarded = [];

    protected $casts = ['findings' => 'array', 'photo_file_ids' => 'array', 'scheduled_at' => 'datetime', 'submitted_at' => 'datetime', 'closed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'audit_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
