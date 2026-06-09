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

    public const OPEN = 'OPEN';

    public const ASSIGNED = 'ASSIGNED';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const PENDING_WO = 'PENDING_WO';

    public const UNDER_REVIEW = 'UNDER_REVIEW';

    public const RESOLVED = 'RESOLVED';

    public const CLOSED = 'CLOSED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'ticket';

    protected $primaryKey = 'ticket_id';

    protected string $idPrefix = 'tck';

    protected $guarded = [];

    protected $casts = ['sla_due_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];

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
