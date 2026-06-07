<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-01 derived stock balance per (location, sku). */
class StockBalance extends Model
{
    use HasPrefixedId;

    protected $table = 'stock_balance';

    protected string $idPrefix = 'bal';

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:2'];
}
