<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** BIL-02-TAX-01 fiscalised tax invoice. */
class TaxInvoice extends Model
{
    use HasPrefixedId;

    protected $table = 'tax_invoice';

    protected $primaryKey = 'tax_invoice_id';

    protected string $idPrefix = 'tinv';

    protected $guarded = [];

    protected $casts = ['response' => 'array', 'issued_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'tax_invoice_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
