<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PLM-CFG-04 / SIP-03 / SIP-05 — discount_assignment. */
class DiscountAssignment extends Model
{
    use HasPrefixedId;

    protected $table = 'discount_assignment';

    protected $primaryKey = 'assignment_id';

    protected string $idPrefix = 'dasg';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    protected $casts = ['effective_from' => 'datetime', 'effective_until' => 'datetime', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'stackable' => 'boolean', 'active' => 'boolean', 'value' => 'decimal:4'];
}
