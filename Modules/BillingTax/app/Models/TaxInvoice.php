<?php

namespace Modules\Billing\Tax\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-TAX-01 tax invoice. The legal document declaring tax on a received payment, signed
 * asynchronously by the operator's tax-authority gateway. Lifecycle:
 *   GENERATED -> PENDING_SIGNATURE -> SIGNED | SIGNING_FAILED -> GAVE_UP_AUTO; plus CANCELLED.
 *
 * @property string $tax_invoice_id
 * @property string $status
 * @property int $retry_count
 */
class TaxInvoice extends Model
{
    use HasPrefixedId;

    public const GENERATED = 'GENERATED';
    public const PENDING_SIGNATURE = 'PENDING_SIGNATURE';
    public const SIGNED = 'SIGNED';
    public const SIGNING_FAILED = 'SIGNING_FAILED';
    public const GAVE_UP_AUTO = 'GAVE_UP_AUTO';
    public const CANCELLED = 'CANCELLED';

    public const TRIGGER_PAYMENT_APPLIED = 'PAYMENT_APPLIED';
    public const TRIGGER_WALLET_TOPPED_UP = 'WALLET_TOPPED_UP';
    public const TRIGGER_PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';

    /** Unsigned states — cancellable with regular privileges, no gateway call (C-2). */
    public const UNSIGNED = [self::GENERATED, self::PENDING_SIGNATURE, self::SIGNING_FAILED, self::GAVE_UP_AUTO];

    protected $table = 'tax_invoice';

    protected $primaryKey = 'tax_invoice_id';

    protected string $idPrefix = 'tinv';

    protected $guarded = [];

    protected $casts = [
        'response' => 'array',
        'tax_summary' => 'array',
        'line_items' => 'array',
        'customer_snapshot' => 'array',
        'metadata' => 'array',
        'subtotal_amount' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'retry_count' => 'integer',
        'issued_at' => 'datetime',
        'signed_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'tax_invoice_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function failures()
    {
        return $this->hasMany(TaxInvoiceSigningFailure::class, 'tax_invoice_id', 'tax_invoice_id');
    }

    public function isSigned(): bool
    {
        return $this->status === self::SIGNED;
    }
}
