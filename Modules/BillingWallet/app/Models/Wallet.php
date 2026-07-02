<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * BIL-05 wallet for PREPAID subscriptions. A subscription may hold several —
 * one per PLM-CFG-03 catalog walletRef (MONEY_KES, VOICE_KES, points…); the
 * catalog governs behaviour (currency, refillability, charging precedence,
 * expiry), this row holds the customer's balance.
 *
 * @property string $wallet_id
 * @property string $balance
 */
class Wallet extends Model
{
    use HasPrefixedId;

    public const ACTIVE = 'ACTIVE';

    protected $table = 'wallet';

    protected $primaryKey = 'wallet_id';

    protected string $idPrefix = 'wlt';

    protected $guarded = [];

    protected $casts = ['balance' => 'decimal:2', 'expires_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'wallet_id';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'wallet_id', 'wallet_id');
    }
}
