<?php

namespace Modules\Ticketing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** TCK-01 ticket comment. */
class TicketComment extends Model
{
    use HasPrefixedId;

    protected $table = 'ticket_comment';

    protected string $idPrefix = 'tcm';

    protected $guarded = [];

    protected $casts = ['internal' => 'boolean'];
}
