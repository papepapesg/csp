<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-01-PAY-01 per-account surplus credit balance (overpayment held for future
 * invoices).
 *
 * @property string $account_id
 * @property string $balance
 */
class AccountCreditBalance extends Model
{
    protected $table = 'account_credit_balance';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['balance' => 'decimal:2'];
}
