<?php

namespace Modules\Billing\Intent\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-01 billable-event intent raised by a subscription operation in its commit
 * window. Lifecycle: PENDING (pay-first gate open) → CHARGED (invoice raised,
 * not gating) / CONFIRMED (settled — inline, on InvoicePaid, or on wallet
 * top-up). The settlement channel records HOW the money side was handled:
 * NONE (nothing to charge / applicability skip), WALLET (prepaid debit),
 * INVOICE (postpaid fee invoice), CREDIT (negative amount posted to the
 * account credit balance).
 *
 * @property string $intent_id
 * @property string $status
 */
class BillingIntent extends Model
{
    use HasPrefixedId;

    // Lifecycle statuses.
    public const PENDING = 'PENDING';

    public const CHARGED = 'CHARGED';

    public const CONFIRMED = 'CONFIRMED';

    // Declared by the BIL-01 DD for manual outcomes; no flow writes them yet.
    public const WAIVED = 'WAIVED';

    public const REFUNDED = 'REFUNDED';

    // Settlement channels — how the money side of the intent was handled.
    public const CHANNEL_NONE = 'NONE';

    public const CHANNEL_WALLET = 'WALLET';

    public const CHANNEL_INVOICE = 'INVOICE';

    public const CHANNEL_CREDIT = 'CREDIT';

    // Billing modes an intent settles under.
    public const POSTPAID = 'POSTPAID';

    public const PREPAID = 'PREPAID';

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
