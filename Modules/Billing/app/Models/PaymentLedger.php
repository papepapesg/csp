<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BIL-01-PAY-01 payment ledger row (inbound money).
 *
 * @property string $payment_id
 */
class PaymentLedger extends Model
{
    use HasPrefixedId;

    protected $table = 'payment_ledger';

    protected $primaryKey = 'payment_id';

    protected string $idPrefix = 'pay';

    protected $guarded = [];

    protected $casts = [
        'received_at' => 'datetime',
        'paid_amount' => 'decimal:2',
        'unallocated_amount' => 'decimal:2',
    ];

    public function getRouteKeyName(): string
    {
        return 'payment_id';
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class, 'payment_id', 'payment_id');
    }
}
