<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** OSR-02 / OSR-05 — stock_count_session. */
class StockCountSession extends Model
{
    use HasPrefixedId;

    protected $table = 'stock_count_session';

    protected $primaryKey = 'session_id';

    protected string $idPrefix = 'scs';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'session_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
