<?php

namespace Modules\Notification\Models\Icn;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * ICN-01 per-(recipient x channel) delivery attempt (§3.5). Lifecycle:
 * PENDING -> DISPATCHED -> ACKNOWLEDGED, or FAILED -> (retry) -> TERMINALLY_FAILED, or
 * SUPPRESSED (quiet hours / opted out / parent ACKed / binding disabled).
 *
 * @property string $delivery_id
 * @property string $status
 * @property int $attempts
 */
class StaffNotificationDelivery extends Model
{
    use HasPrefixedId;

    public const PENDING = 'PENDING';
    public const DISPATCHED = 'DISPATCHED';
    public const ACKNOWLEDGED = 'ACKNOWLEDGED';
    public const FAILED = 'FAILED';
    public const TERMINALLY_FAILED = 'TERMINALLY_FAILED';
    public const SUPPRESSED = 'SUPPRESSED';

    protected $table = 'staff_notification_delivery';

    protected $primaryKey = 'delivery_id';

    protected string $idPrefix = 'deliv';

    protected $guarded = [];

    protected $casts = [
        'provider_response' => 'array',
        'attempts' => 'int',
        'channel_priority_idx' => 'int',
        'last_attempt_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'delivery_id';
    }

    public function notification()
    {
        return $this->belongsTo(StaffNotification::class, 'notification_id', 'notification_id');
    }
}
