<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;

/** DD_SUB-WF-PAUSE-01 §7.3 per-operator voluntary-pause switches and limits. */
class SubscriptionPauseConfig extends Model
{
    protected $table = 'subscription_pause_config';

    protected $primaryKey = 'operator_code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'customer_self_service_enabled' => 'boolean',
        'scheduled_pause_enabled' => 'boolean',
        'max_future_scheduled_resume_days' => 'integer',
        'min_pause_hours' => 'integer',
        'customer_notification_enabled' => 'boolean',
    ];

    public static function forOperator(string $operator): ?self
    {
        return static::query()->find($operator);
    }
}
