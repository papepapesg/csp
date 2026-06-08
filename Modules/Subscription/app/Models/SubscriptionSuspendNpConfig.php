<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/** DD_SUB-WF-SUSPEND-NP-01 §3.1 per-operator non-payment-suspension config. */
class SubscriptionSuspendNpConfig extends Model
{
    protected $table = 'subscription_suspend_np_config';

    protected $primaryKey = 'operator_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'customer_notification_enabled' => 'boolean',
        'debt_amount_warning_threshold' => 'decimal:2',
    ];

    public static function forOperator(string $operator): ?self
    {
        return static::query()->find($operator);
    }

    /** §3.1: debtAmountTier=HIGH when the outstanding debt exceeds the warning threshold. */
    public function debtTier(float $outstandingDebt): string
    {
        if ($this->debt_amount_warning_threshold === null) {
            return 'STANDARD';
        }

        return $outstandingDebt > (float) $this->debt_amount_warning_threshold ? 'HIGH' : 'STANDARD';
    }
}
