<?php

namespace Modules\Workforce\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Workforce\Services\ContractorAvailabilityService;

/**
 * EM-02 §3.6 WO -> capacity trigger. A finalized work order CONSUMES the contractor
 * slot capacity it reserved; a cancelled one RELEASES it (capacity restored). Without
 * this, committed capacity stayed ACTIVE forever and the slot leaked. Event-driven so
 * Workforce and WO stay decoupled — mirrors OSR's reservation lifecycle listener.
 */
class ResolveSlotCommitmentOnWoLifecycle
{
    public function __construct(private readonly ContractorAvailabilityService $capacity) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        $woId = $event->payload['workOrderId'] ?? null;
        if (! $woId) {
            return;
        }

        match ($event->event_type) {
            'WorkOrderFinalized' => $this->capacity->consumeForWorkOrder($woId),
            'WorkOrderCancelled' => $this->capacity->releaseForWorkOrder($woId),
            default => null,
        };
    }
}
