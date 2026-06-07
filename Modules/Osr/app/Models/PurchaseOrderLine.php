<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-02 / OSR-05 — purchase_order_line. */
class PurchaseOrderLine extends Model
{
    use HasPrefixedId;

    protected $table = 'purchase_order_line';

    protected $primaryKey = 'po_line_id';

    protected string $idPrefix = 'pol';

    protected $guarded = [];
}
