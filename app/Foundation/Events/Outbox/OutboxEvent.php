<?php

namespace App\Foundation\Events\Outbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $event_id
 * @property string $event_type
 * @property string $topic
 * @property array $payload
 * @property Carbon|null $published_at
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'published_at' => 'datetime',
    ];
}
