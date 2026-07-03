<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-03 wallet type — THE single wallet catalog level: defines a wallet a
 * customer can hold (code = the walletRef Services reference) and how it behaves
 * (role, unit, precedence, refillability, expiry, points valuation).
 *
 * `role` is the primary axis — it answers "what is this wallet FOR", which
 * subsumes the old prepaid/postpaid `applicability` guessing:
 *   SETTLEMENT  a spendable balance that drains to settle prepaid charges
 *               (unit currency or points). The only role the charging path
 *               selects — so "postpaid can't settle from a wallet" is expressed
 *               by there being no settlement wallet on that path, not a flag.
 *   DEPOSIT     a held security balance — refunded, never charged at cycle.
 *   ALLOWANCE   in-kind units (DATA/SMS/VOICE) consumed by usage; overage bills.
 *
 * Deliberately currency-free: a deployment transacts in ONE currency
 * (operator_config.currency_code); nothing here (or on the wallet instance)
 * carries currency, and codes are currency-neutral (MONEY, not MONEY_KES). The
 * per-customer balance lives in the wallet/wallet_transaction ledger, not here.
 *
 * @property string $code
 * @property string $role   SETTLEMENT | DEPOSIT | ALLOWANCE
 * @property string $unit   currency | points | usage measure
 * @property int $charging_precedence
 */
class WalletType extends Model
{
    use HasPrefixedId;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    // Roles (R-W-7): what the wallet is FOR.
    public const ROLE_SETTLEMENT = 'SETTLEMENT';

    public const ROLE_DEPOSIT = 'DEPOSIT';

    public const ROLE_ALLOWANCE = 'ALLOWANCE';

    public const ROLES = [self::ROLE_SETTLEMENT, self::ROLE_DEPOSIT, self::ROLE_ALLOWANCE];

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

    /** R-W-7: a spendable balance the charging path drains to settle prepaid charges. */
    public function isSettlement(): bool
    {
        return $this->role === self::ROLE_SETTLEMENT;
    }

    /** An in-kind allowance (DATA/SMS/VOICE) consumed by usage; overage bills. */
    public function isAllowance(): bool
    {
        return $this->role === self::ROLE_ALLOWANCE;
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
