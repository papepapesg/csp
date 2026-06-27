<?php

namespace Modules\Billing\Payments\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** BIL-01-PAY-01 payment-to-invoice allocation. */
class PaymentAllocation extends Model
{
    use HasPrefixedId;

    protected $table = 'payment_invoice_allocation';

    protected string $idPrefix = 'alloc';

    protected $guarded = [];

    protected $casts = ['allocated_amount' => 'decimal:2'];
}
