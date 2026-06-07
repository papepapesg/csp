<?php

namespace App\Foundation\Events\Outbox;

use Illuminate\Database\Eloquent\Model;

/**
 * Consumer inbox row — guarantees an event is processed at most once per
 * consumer (HLD §6.6). Handlers call InboxEvent::firstOrCreate() before doing
 * work and skip if it already exists / is processed.
 *
 * @property string $event_id
 * @property string $consumer
 */
class InboxEvent extends Model
{
    protected $table = 'inbox_events';

    protected $guarded = [];

    protected $casts = [
        'processed_at' => 'datetime',
    ];
}
