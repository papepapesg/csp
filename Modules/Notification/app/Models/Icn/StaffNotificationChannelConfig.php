<?php

namespace Modules\Notification\Models\Icn;

use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 operator DOMAIN channel config (§3.2): which channels, default priority, fallback
 * mode, retry policy, ack window. Provider endpoints/secrets live in adapter_binding, never
 * here — so a channel can swap providers without touching domain config.
 */
class StaffNotificationChannelConfig extends Model
{
    public const PARALLEL = 'PARALLEL';
    public const SEQUENTIAL_UNTIL_ACK = 'SEQUENTIAL_UNTIL_ACK';
    public const SEQUENTIAL_UNTIL_DISPATCH = 'SEQUENTIAL_UNTIL_DISPATCH';

    protected $table = 'staff_notification_channel_config';

    public $incrementing = false;

    protected $primaryKey = 'operator_code';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'enabled_channels' => 'array',
        'default_priority' => 'array',
        'retry_backoff_seconds' => 'array',
        'retry_max_attempts' => 'int',
        'ack_window_hours' => 'int',
        'updated_at' => 'datetime',
    ];

    public static function forOperator(string $operator): ?self
    {
        return static::query()->whereKey($operator)->first();
    }
}
