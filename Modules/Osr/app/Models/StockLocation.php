<?php

namespace Modules\Osr\Models;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** OSR-01 stock location (warehouse or contractor van). */
class StockLocation extends Model
{
    protected $table = 'stock_location';

    protected $primaryKey = 'location_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'location_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
