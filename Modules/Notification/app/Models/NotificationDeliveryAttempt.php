<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 per-channel delivery attempt (R-NOT-01-C-5 / F-6). One row per try; repeated
 * failures on the same notification accumulate rows, preserving full retry history. The
 * retry scanner reads (status=PENDING_RETRY, next_attempt_at<=now).
 *
 * @property string $id
 * @property int $attempt_number
 * @property string $status
 */
class NotificationDeliveryAttempt extends Model
{
    use HasPrefixedId;

    public const SENT = 'SENT';
    public const FAILED = 'FAILED';
    public const PENDING_RETRY = 'PENDING_RETRY';
    public const ESCALATED = 'ESCALATED';

    protected $table = 'notification_delivery_attempt';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'att';

    protected $guarded = [];

    protected $casts = [
        'channel_response' => 'array',
        'dispatch_context' => 'array',
        'attempt_number' => 'int',
        'attempted_at' => 'datetime',
        'next_attempt_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
