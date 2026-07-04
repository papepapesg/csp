<?php

namespace Modules\Billing\Mediation\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** MED-01 / RAT-01 — a mediated CDR (raw usage), rated into a RatedEvent. */
class UsageRecord extends Model
{
    use HasPrefixedId;

    // Lifecycle: ingested RECEIVED, then priced to RATED.
    public const STATUS_RECEIVED = 'RECEIVED';

    public const STATUS_RATED = 'RATED';

    // Usage types the rating engine knows how to price.
    public const TYPE_VOICE = 'VOICE';

    public const TYPE_DATA = 'DATA';

    public const TYPE_SMS = 'SMS';

    protected $table = 'usage_record';

    protected $primaryKey = 'usage_id';

    protected string $idPrefix = 'use';

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:4', 'occurred_at' => 'datetime', 'raw' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
