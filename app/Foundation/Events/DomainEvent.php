<?php

namespace App\Foundation\Events;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;

/**
 * Immutable domain-event envelope (FOUNDATION_KAFKA).
 *
 * A domain event is a committed business fact. It is recorded via the EventBus
 * (transactional outbox) and later published to the transport. The envelope
 * carries enough context (operator, correlation, aggregate) for idempotent,
 * traceable downstream processing.
 */
final class DomainEvent
{
    public readonly string $eventId;

    public readonly string $correlationId;

    public readonly string $operatorCode;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function __construct(
        public readonly string $type,
        public readonly string $topic,
        public readonly array $payload,
        public readonly ?string $aggregateType = null,
        public readonly ?string $aggregateId = null,
        public readonly array $headers = [],
        ?string $eventId = null,
        ?string $correlationId = null,
        ?string $operatorCode = null,
    ) {
        $this->eventId = $eventId ?? Id::make('evt');
        $this->correlationId = $correlationId ?? Context::correlationId();
        $this->operatorCode = $operatorCode ?? Context::operatorCode();
    }
}
