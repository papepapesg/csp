<?php

namespace App\Foundation\Events\Drivers;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Events\Outbox\OutboxEvent;

/**
 * Kafka event bus (swap-in for SOPHIX_EVENT_BUS=kafka).
 *
 * To keep delivery atomic with the business write we still persist to the outbox
 * first; the OutboxDispatcher is responsible for producing to Kafka. This class
 * exists so the wiring/contract is identical and a real producer (e.g.
 * rdkafka / mateusjunges/laravel-kafka) can be dropped into produce().
 */
class KafkaEventBus implements EventBus
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

    /**
     * Produce a committed outbox row to Kafka. Wire a real producer here.
     */
    public function produce(OutboxEvent $row): void
    {
        $topic = config('sophix.kafka.topic_prefix', 'sophix').'.'.$row->topic;
        throw new \RuntimeException("Kafka publisher is not installed; event {$row->event_id} was not published to {$topic}.");
    }
}
