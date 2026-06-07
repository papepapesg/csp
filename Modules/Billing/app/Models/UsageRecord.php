<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** MED-01 / RAT-01 — usage_record. */
class UsageRecord extends Model
{
    use HasPrefixedId;

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
