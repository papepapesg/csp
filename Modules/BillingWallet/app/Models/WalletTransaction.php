<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-05 wallet transaction — one append-only ledger row per balance movement,
 * carrying the running balance_after. Two distinct fields, deliberately split:
 *
 *  - `movement_type`  the CATALOGED, actionable classification (closed set). The
 *    code branches on it — which event fires, whether refillability/expiry apply.
 *    Callers pass a MOVEMENT_* constant; API callers can never choose it (the
 *    endpoints fix it), so a load-bearing token like TOPUP can't be injected.
 *  - `reason`  a free descriptive audit label — the ORIGINATING code (an
 *    intent_type, a note type, a usage label). Nothing branches on it.
 */
class WalletTransaction extends Model
{
    use HasPrefixedId;

    // Directions.
    public const CREDIT = 'CREDIT';

    public const DEBIT = 'DEBIT';

    // Movement types (R-W-17) — the cataloged, actionable classification.
    /** Money top-up: gated by refillability (R-W-11), restarts expiry (R-W-9), emits WalletToppedUp. */
    public const MOVEMENT_TOPUP = 'TOPUP';

    /** A debit that settles money owed (cycle / usage / intent settlement / debit note). */
    public const MOVEMENT_CHARGE = 'CHARGE';

    /** A non-top-up balance credit (credit note, bonus, refund) — no resume/refillability/expiry. */
    public const MOVEMENT_CREDIT = 'CREDIT';

    /** Allowance bundle granted in-kind (e.g. +5GB). */
    public const MOVEMENT_ALLOWANCE_GRANT = 'ALLOWANCE_GRANT';

    /** Allowance consumed in-kind by rated usage. */
    public const MOVEMENT_ALLOWANCE_USE = 'ALLOWANCE_USE';

    /** Balance zeroed by the R-W-9 expiry sweep. */
    public const MOVEMENT_EXPIRY = 'EXPIRY';

    public const MOVEMENTS = [
        self::MOVEMENT_TOPUP, self::MOVEMENT_CHARGE, self::MOVEMENT_CREDIT,
        self::MOVEMENT_ALLOWANCE_GRANT, self::MOVEMENT_ALLOWANCE_USE, self::MOVEMENT_EXPIRY,
    ];

    protected $table = 'wallet_transaction';

    protected string $idPrefix = 'wtx';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
}
