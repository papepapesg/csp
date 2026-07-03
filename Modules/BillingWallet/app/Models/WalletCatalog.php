<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-03 `wallet` catalog entry. Defines a wallet a customer can hold and how
 * it behaves at charging time. Referenced by Services via `default_wallet_ref` =
 * this entry's `code` (the walletRef). The per-customer balance lives in the
 * BIL-06 ledger, not here.
 *
 * @property string $code
 * @property string $applicability  PREPAID_ONLY | POSTPAID_ONLY | ANY
 * @property int $charging_precedence
 */
class WalletCatalog extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const PREPAID_ONLY = 'PREPAID_ONLY';

    public const POSTPAID_ONLY = 'POSTPAID_ONLY';

    public const ANY = 'ANY';

    protected $table = 'wallet_catalog';

    protected $primaryKey = 'wallet_catalog_id';

    protected string $idPrefix = 'wcat';

    protected $guarded = [];

    protected $casts = [
        'decimal_precision' => 'integer',
        'expires' => 'boolean',
        'expiry_period_days' => 'integer',
        'charging_precedence' => 'integer',
        'refillable' => 'boolean',
        'points_to_currency_rate' => 'decimal:6',
        'retired_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function getRouteKeyName(): string
    {
        return 'wallet_catalog_id';
    }

    /** R-W-7: can a subscription on this billing mode charge against this wallet? */
    public function appliesToBillingMode(string $billingMode): bool
    {
        return match ($this->applicability) {
            self::ANY => true,
            self::PREPAID_ONLY => $billingMode === 'PREPAID',
            self::POSTPAID_ONLY => $billingMode === 'POSTPAID',
            default => false,
        };
    }

    /** Resolve an ACTIVE wallet catalog entry by its walletRef code, or null. */
    public static function activeByCode(string $operator, string $code): ?self
    {
        return static::query()
            ->where('operator_code', $operator)
            ->where('code', $code)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }
}
