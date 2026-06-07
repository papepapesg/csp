<?php

namespace App\Foundation\Events;

/**
 * Event bus contract (FOUNDATION_KAFKA).
 *
 * Implementations decide the transport. The default OutboxEventBus records the
 * event in the transactional outbox; a KafkaEventBus can publish directly when
 * SOPHIX_EVENT_BUS=kafka. Modules depend only on this interface.
 */
interface EventBus
{
    public function publish(DomainEvent $event): void;
}
