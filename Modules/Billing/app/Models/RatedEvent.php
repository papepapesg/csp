<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MED-01 / RAT-01 — rated_event: one immutable rated usage result, the source
 * of truth for usage charge audit. invoice_id links it to the invoice that
 * consumed it (RAT-01 mark-invoiced), enabling itemized usage pages.
 */
class RatedEvent extends Model
{
    use HasPrefixedId;

    protected $table = 'rated_event';

    protected $primaryKey = 'rated_id';

    protected string $idPrefix = 'rat';

    protected $guarded = [];

    protected $casts = ['rate' => 'decimal:4', 'amount' => 'decimal:4', 'billed' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /** The mediated CDR this rating priced (destination, occurred_at, quantity). */
    public function usage(): BelongsTo
    {
        return $this->belongsTo(UsageRecord::class, 'usage_id', 'usage_id');
    }
}
