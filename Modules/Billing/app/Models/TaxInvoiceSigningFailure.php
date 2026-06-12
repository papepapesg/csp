<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** BIL-02-TAX-01 signing-attempt failure audit (R-TAX-01-F-5). One row per failed attempt. */
class TaxInvoiceSigningFailure extends Model
{
    use HasPrefixedId;

    public const TRANSIENT = 'TRANSIENT';
    public const VALIDATION = 'VALIDATION';

    protected $table = 'tax_invoice_signing_failure';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'tisf';

    protected $guarded = [];

    protected $casts = [
        'attempt_number' => 'integer',
        'failed_at' => 'datetime',
        'retry_scheduled_at' => 'datetime',
    ];
}
