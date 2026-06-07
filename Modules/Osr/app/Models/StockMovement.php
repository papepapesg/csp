<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-01 stock movement (append-only). */
class StockMovement extends Model
{
    use HasPrefixedId;

    protected $table = 'stock_movement';

    protected string $idPrefix = 'mov';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['quantity' => 'decimal:2', 'created_at' => 'datetime'];
}
