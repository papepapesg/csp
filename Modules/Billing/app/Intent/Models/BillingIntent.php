<?php

namespace Modules\Billing\Intent\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** BIL-01 billable-event intent raised by a subscription operation. */
class BillingIntent extends Model
{
    use HasPrefixedId;

    public const PENDING = 'PENDING';

    public const CHARGED = 'CHARGED';

    public const CONFIRMED = 'CONFIRMED';

    public const WAIVED = 'WAIVED';

    public const REFUNDED = 'REFUNDED';

    protected $table = 'billing_intent';

    protected $primaryKey = 'intent_id';

    protected string $idPrefix = 'bint';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'pay_first' => 'boolean', 'confirmed_at' => 'datetime', 'state_callback' => 'array'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
