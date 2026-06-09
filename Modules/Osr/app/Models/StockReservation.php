<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** OSR-01 §1.5 stock reservation — committed-but-not-yet-installed qty held for a WO. */
class StockReservation extends Model
{
    use HasPrefixedId;

    public const ACTIVE = 'ACTIVE';

    public const CONSUMED = 'CONSUMED';

    public const RELEASED = 'RELEASED';

    protected $table = 'stock_reservation';

    protected $primaryKey = 'reservation_id';

    protected string $idPrefix = 'rsv';

    protected $guarded = [];

    protected $casts = ['qty' => 'decimal:2', 'resolved_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
