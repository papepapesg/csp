<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** BIL-02 invoice line. */
class InvoiceLine extends Model
{
    use HasPrefixedId;

    protected $table = 'invoice_line';

    protected string $idPrefix = 'invl';

    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];
}
