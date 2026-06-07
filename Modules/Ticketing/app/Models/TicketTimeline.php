<?php

namespace Modules\Ticketing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** TCK-01 ticket timeline entry (append-only). */
class TicketTimeline extends Model
{
    use HasPrefixedId;

    protected $table = 'ticket_timeline';

    protected string $idPrefix = 'ttl';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['meta' => 'array', 'created_at' => 'datetime'];
}
