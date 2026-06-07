<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SUB-WF-UPGRADE-01 / DOWNGRADE-01 per-operator + per-kind config row.
 *
 * @property bool $customer_self_service_enabled
 * @property bool $pay_first_required
 * @property string $default_effective_timing
 */
class SubscriptionUpgradeConfig extends Model
{
    protected $table = 'subscription_upgrade_config';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'customer_self_service_enabled' => 'boolean',
        'pay_first_required' => 'boolean',
        'customer_notification_enabled' => 'boolean',
        'max_future_scheduled_days' => 'integer',
    ];

    public static function forOperator(string $operator, string $kind): self
    {
        return static::query()->where('operator_code', $operator)->where('kind', $kind)->first()
            ?? new self([
                'operator_code' => $operator, 'kind' => $kind,
                'customer_self_service_enabled' => false, 'pay_first_required' => true,
                'default_cycle_anchor_policy' => 'PRESERVE', 'default_effective_timing' => 'IMMEDIATE',
                'max_future_scheduled_days' => 90, 'customer_notification_enabled' => true,
            ]);
    }
}
