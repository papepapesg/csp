<?php

namespace Modules\Notification\Models\Icn;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 dispatch request (§3.4): one row per call to POST /api/staff-notifications, fanning
 * out to N recipients x M channels. Status is PROCESSING -> DISPATCHED -> ACKNOWLEDGED, or
 * EXPIRED (ack window / no recipients / all channels exhausted).
 *
 * @property string $notification_id
 * @property string $status
 */
class StaffNotification extends Model
{
    use HasPrefixedId;

    public const PROCESSING = 'PROCESSING';
    public const DISPATCHED = 'DISPATCHED';
    public const ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const EXPIRED = 'EXPIRED';

    protected $table = 'staff_notification';

    protected $primaryKey = 'notification_id';

    protected string $idPrefix = 'notif';

    protected $guarded = [];

    protected $casts = [
        'template_variables' => 'array',
        'expected_recipients' => 'int',
        'ack_window_hours' => 'int',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'notification_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function deliveries()
    {
        return $this->hasMany(StaffNotificationDelivery::class, 'notification_id', 'notification_id');
    }
}
