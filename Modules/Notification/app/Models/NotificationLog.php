<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 aggregate audit row per notification event (R-NOT-01-A-1). Append-only,
 * P10Y retention. Holds the final cross-channel outcome; the per-channel detail lives
 * in notification_delivery_attempt rows.
 *
 * @property string $id
 * @property string $final_status
 */
class NotificationLog extends Model
{
    use HasPrefixedId;

    public const DISPATCHED = 'DISPATCHED';
    public const PARTIALLY_DISPATCHED = 'PARTIALLY_DISPATCHED';
    public const SUPPRESSED = 'SUPPRESSED';
    public const ESCALATED = 'ESCALATED';
    public const UNDELIVERABLE = 'UNDELIVERABLE';

    protected $table = 'notification_log';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'ntf';

    protected $guarded = [];

    protected $casts = [
        'channels_attempted' => 'array',
        'dispatched_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function attempts()
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'notification_id', 'id');
    }
}
