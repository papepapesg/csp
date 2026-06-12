<?php

namespace Modules\Notification\Models\Icn;

use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 per-user per-channel provider identifier (§3.7): email address, Slack member id,
 * Teams UPN, push marker, ... as opaque identity_jsonb interpreted only by the channel
 * adapter. Absent rows are fine — the adapter falls back to FOUNDATION_AUTH lookup (§7.0).
 */
class StaffNotificationUserChannelIdentity extends Model
{
    protected $table = 'staff_notification_user_channel_identity';

    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'identity_jsonb' => 'array',
        'verified' => 'bool',
        'verified_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static function resolve(string $userId, string $channel): ?self
    {
        return static::query()->where('user_id', $userId)->where('channel', $channel)->first();
    }
}
