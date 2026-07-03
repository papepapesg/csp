<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-05 wallet transaction — one append-only ledger row per balance movement,
 * carrying the running balance_after. The `reason` vocabulary is open (callers
 * pass their own handle: CREDIT_NOTE, an intent type, …); the constants below
 * are the reasons THIS module gives special meaning to.
 */
class WalletTransaction extends Model
{
    use HasPrefixedId;

    // Directions.
    public const CREDIT = 'CREDIT';

    public const DEBIT = 'DEBIT';

    /** Top-up: gated by catalog refillability (R-W-11), restarts the expiry window (R-W-9), emits WalletToppedUp. */
    public const REASON_TOPUP = 'TOPUP';

    /** Default debit reason — the recurring cycle charge. */
    public const REASON_CYCLE_CHARGE = 'CYCLE_CHARGE';

    /** Balance zeroed by the R-W-9 expiry sweep. */
    public const REASON_EXPIRY = 'EXPIRY';

    /** Allowance bundle granted in-kind (e.g. +5GB), not a money top-up. */
    public const REASON_ALLOWANCE_GRANT = 'ALLOWANCE_GRANT';

    /** Allowance consumed in-kind by rated usage (overage bills separately). */
    public const REASON_ALLOWANCE_USE = 'ALLOWANCE_USE';

    protected $table = 'wallet_transaction';

    protected string $idPrefix = 'wtx';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
}
