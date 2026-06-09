<?php

namespace Modules\Ticketing\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Ticketing\Services\TicketService;

/**
 * TCK-01 §9.2 WO event consumption. When a Work Order created from a ticket finalizes,
 * move the waiting ticket (PENDING_WO) to RESOLVED. Event-driven + idempotent (only
 * acts while a ticket still waits on that WO), per TCK-10.
 */
class ResolveTicketOnWorkOrderFinalized
{
    public function __construct(private readonly TicketService $tickets) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'WorkOrderFinalized') {
            return;
        }
        $woId = $event->payload['workOrderId'] ?? null;
        if ($woId) {
            $this->tickets->onWorkOrderFinalized($woId, $event->payload['finalReason'] ?? null);
        }
    }
}
