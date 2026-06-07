<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-02 / OSR-05 — stock_count_line. */
class StockCountLine extends Model
{
    use HasPrefixedId;

    protected $table = 'stock_count_line';

    protected $primaryKey = 'count_line_id';

    protected string $idPrefix = 'scl';

    protected $guarded = [];
}
