<?php

namespace Modules\Notification\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * NOT-01 routing rule (R-NOT-01-R-1). A per-operator mapping
 * (operator_code, event_type) -> {channel, template_purpose_code, priority, urgency,
 * category}. Operators commit their own rule sets; an empty list means "internal only,
 * don't notify" (R-NOT-01-R-6).
 *
 * @property string $id
 * @property int $priority
 * @property bool $enabled
 */
class NotificationRoutingRule extends Model
{
    use HasPrefixedId;

    public const CATEGORY_TRANSACTIONAL = 'TRANSACTIONAL';
    public const CATEGORY_MARKETING = 'MARKETING';

    public const URGENCY_URGENT = 'URGENT';
    public const URGENCY_NORMAL = 'NORMAL';

    protected $table = 'notification_routing_rule';

    protected $primaryKey = 'id';

    protected string $idPrefix = 'nrr';

    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'priority' => 'int',
        'enabled' => 'bool',
        'needs_pdf' => 'bool',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
