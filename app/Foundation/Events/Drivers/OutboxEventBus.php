<?php

namespace App\Foundation\Events\Drivers;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Events\Outbox\OutboxEvent;

/**
 * Default event bus: records events in the transactional outbox (HLD §6.6).
 *
 * publish() only writes a row — it MUST be called inside the same DB transaction
 * as the business state change. The OutboxDispatcher later forwards committed
 * rows to the real transport (in-process listeners for the native driver, or
 * Kafka when configured).
 */
class OutboxEventBus implements EventBus
{
    public function publish(DomainEvent $event): void
    {
        OutboxEvent::query()->create([
            'event_id' => $event->eventId,
            'event_type' => $event->type,
            'topic' => $event->topic,
            'aggregate_type' => $event->aggregateType,
            'aggregate_id' => $event->aggregateId,
            'operator_code' => $event->operatorCode,
            'correlation_id' => $event->correlationId,
            'payload' => $event->payload,
            'headers' => $event->headers,
        ]);
    }
}
