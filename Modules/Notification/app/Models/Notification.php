<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 notification record.
 *
 * @property string $notification_id
 * @property string $status
 */
class Notification extends Model
{
    use HasPrefixedId;

    public const QUEUED = 'QUEUED';

    public const SENT = 'SENT';

    public const FAILED = 'FAILED';

    public const DELIVERED = 'DELIVERED';

    protected $table = 'notification';

    protected $primaryKey = 'notification_id';

    protected string $idPrefix = 'ntf';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'sent_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'notification_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
