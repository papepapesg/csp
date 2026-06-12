<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-03 discount assignment — a governed grant of a PLM-CFG-04 discount to a target scope.
 * Lifecycle: DRAFT/PENDING_APPROVAL -> ACTIVE -> SUSPENDED/EXPIRED/CANCELLED (or REJECTED).
 * SIP-03 governs the record; DIS-OP-01 reads ACTIVE rows at billing time and applies the money.
 *
 * @property string $assignment_id
 * @property string $status
 */
class DiscountAssignment extends Model
{
    use HasPrefixedId;

    public const DRAFT = 'DRAFT';
    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const ACTIVE = 'ACTIVE';
    public const SUSPENDED = 'SUSPENDED';
    public const EXPIRED = 'EXPIRED';
    public const CANCELLED = 'CANCELLED';
    public const REJECTED = 'REJECTED';

    protected $table = 'discount_assignment';

    protected $primaryKey = 'assignment_id';

    protected string $idPrefix = 'dasg';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    protected $casts = [
        'effective_from' => 'datetime', 'effective_until' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime',
        'valid_from' => 'date', 'valid_to' => 'date', 'activated_at' => 'datetime', 'cancelled_at' => 'datetime',
        'stackable' => 'boolean', 'active' => 'boolean', 'value' => 'decimal:4', 'metadata_json' => 'array', 'assignment_priority' => 'integer',
    ];

    public function getRouteKeyName(): string
    {
        return 'assignment_id';
    }
}
