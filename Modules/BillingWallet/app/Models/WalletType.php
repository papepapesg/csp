<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-03 wallet type — THE single wallet catalog level: defines a wallet a
 * customer can hold (code = the walletRef Services reference) and how it
 * behaves at charging time (unit, applicability, precedence, refillability,
 * expiry, points valuation).
 *
 * Deliberately currency-free: a deployment transacts in ONE currency
 * (operator_config.currency_code) and wallet instances are stamped with it at
 * creation — a catalog row cannot introduce a second currency, and codes are
 * currency-neutral (MONEY, not a per-currency MONEY_XXX). The per-customer balance lives in
 * the wallet/wallet_transaction ledger, not here.
 *
 * @property string $code
 * @property string $unit           currency | points
 * @property string $applicability  PREPAID_ONLY | POSTPAID_ONLY | ANY
 * @property int $charging_precedence
 */
class WalletType extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    public const PREPAID_ONLY = 'PREPAID_ONLY';

    public const POSTPAID_ONLY = 'POSTPAID_ONLY';

    public const ANY = 'ANY';

    // Units (R-W-15): what the balance counts.
    public const UNIT_CURRENCY = 'currency';

    public const UNIT_POINTS = 'points';

    protected $table = 'wallet_type';

    protected $primaryKey = 'wallet_type_id';

    protected string $idPrefix = 'wtyp';

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
        return 'wallet_type_id';
    }

    /** R-W-15: a points wallet is valued in currency via points_to_currency_rate. */
    public function isPoints(): bool
    {
        return $this->unit === self::UNIT_POINTS;
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

    /** Resolve an ACTIVE wallet type by its walletRef code, or null. */
    public static function activeByCode(string $operator, string $code): ?self
    {
        return static::query()
            ->where('operator_code', $operator)
            ->where('code', $code)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }
}
