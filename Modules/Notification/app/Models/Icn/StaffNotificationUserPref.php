<?php

namespace Modules\Notification\Models\Icn;

use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 per-user DOMAIN preference overrides (§3.3): channels, priority, quiet hours,
 * suppression. Per-channel provider identifiers live in user_channel_identity, not here.
 */
class StaffNotificationUserPref extends Model
{
    protected $table = 'staff_notification_user_pref';

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'enabled_channels' => 'array',
        'preferred_priority' => 'array',
        'suppress_channels' => 'array',
        'updated_at' => 'datetime',
    ];

    public static function forUser(string $userId): ?self
    {
        return static::query()->whereKey($userId)->first();
    }
}
