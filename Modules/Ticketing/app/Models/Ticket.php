<?php

namespace Modules\Ticketing\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * TCK-01 ticket / case.
 *
 * @property string $ticket_id
 * @property string $status
 */
class Ticket extends Model
{
    use HasPrefixedId;
    use \App\Foundation\Tenancy\BelongsToOperator;

    public const OPEN = 'OPEN';

    public const TRIAGED = 'TRIAGED';

    public const ASSIGNED = 'ASSIGNED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    // TCK-01 §5 WAITING states (WAITING_WORK_ORDER supersedes the legacy PENDING_WO alias).
    public const WAITING_CUSTOMER = 'WAITING_CUSTOMER';

    public const WAITING_INTERNAL = 'WAITING_INTERNAL';

    public const WAITING_WORK_ORDER = 'WAITING_WORK_ORDER';

    public const PENDING_WO = 'PENDING_WO';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const RESOLVED = 'RESOLVED';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'ticket';

    protected $primaryKey = 'ticket_id';

    protected string $idPrefix = 'tck';

    protected $guarded = [];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'first_response_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'requires_review' => 'boolean',
    ];

    public function getRouteKeyName(): string
    {
        return 'ticket_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class, 'ticket_id', 'ticket_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(TicketTimeline::class, 'ticket_id', 'ticket_id');
    }
}
