<?php

namespace Modules\Ticketing\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Ticketing\Services\TicketService;

/**
 * TCK-01 §9.2 WO event consumption. When a Work Order created from a ticket finalizes,
 * move the waiting ticket (WAITING_WORK_ORDER) to RESOLVED/UNDER_REVIEW; when it is
 * cancelled, send the ticket back to ASSIGNED/WAITING_INTERNAL for review. Event-driven
 * + idempotent (only acts while a ticket still waits on that WO), per TCK-10.
 */
class ResolveTicketOnWorkOrderFinalized
{
    public function __construct(private readonly TicketService $tickets) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        $woId = $event->payload['workOrderId'] ?? null;
        if (! $woId) {
            return;
        }
        match ($event->event_type) {
            'WorkOrderFinalized' => $this->tickets->onWorkOrderFinalized($woId, $event->payload['finalReason'] ?? null),
            'WorkOrderCancelled' => $this->tickets->onWorkOrderCancelled($woId, $event->payload['reason'] ?? $event->payload['cancelReason'] ?? null),
            default => null,
        };
    }
}
