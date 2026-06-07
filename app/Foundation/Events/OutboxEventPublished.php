<?php

namespace App\Foundation\Events;

use App\Foundation\Events\Outbox\OutboxEvent;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the OutboxDispatcher for the native driver once a committed outbox
 * row is forwarded. Module consumers subscribe to this (typically queued +
 * inbox-guarded) to react to facts from other modules without cross-module DB
 * writes.
 */
class OutboxEventPublished
{
    use Dispatchable;

    public function __construct(public readonly OutboxEvent $event) {}
}
