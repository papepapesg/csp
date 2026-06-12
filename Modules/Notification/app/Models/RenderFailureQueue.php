<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 render failure queue (R-NOT-01-D-2 / D-7). A render that could not produce its
 * artifact (template missing, engine error, malformed payload) lands here for auto-retry
 * and, after the retry budget, admin recovery. Holds the original event payload so a
 * re-render can be replayed.
 *
 * @property string $id
 * @property string $status
 */
class RenderFailureQueue extends Model
{
    use HasPrefixedId;

    public const PENDING_RETRY = 'PENDING_RETRY';
    public const GAVE_UP_AUTO = 'GAVE_UP_AUTO';
    public const RESOLVED = 'RESOLVED';

    public const TEMPLATE_NOT_FOUND = 'TEMPLATE_NOT_FOUND';
    public const RENDER_ENGINE_ERROR = 'RENDER_ENGINE_ERROR';
    public const MALFORMED_PAYLOAD = 'MALFORMED_PAYLOAD';

    protected $table = 'render_failure_queue';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'rfq';

    protected $guarded = [];

    protected $casts = [
        'original_event_payload' => 'array',
        'attempt_count' => 'int',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
