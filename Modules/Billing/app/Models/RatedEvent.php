<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** MED-01 / RAT-01 — rated_event. */
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
}
