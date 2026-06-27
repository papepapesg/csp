<?php

namespace Modules\Billing\Wallet\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** BIL-05 wallet transaction ledger row. */
class WalletTransaction extends Model
{
    use HasPrefixedId;

    protected $table = 'wallet_transaction';

    protected string $idPrefix = 'wtx';

    protected $guarded = [];

    protected $casts = ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
}
