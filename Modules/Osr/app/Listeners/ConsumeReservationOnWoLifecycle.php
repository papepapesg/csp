<?php

namespace Modules\Osr\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Osr\Services\StockService;

/**
 * OSR-01 §2.3 WO->stock trigger. A completed work order CONSUMES its stock
 * reservations (the install movement that deducts materials from the van); a
 * cancelled work order RELEASES them. Event-driven so OSR and WO stay decoupled.
 */
class ConsumeReservationOnWoLifecycle
{
    public function __construct(private readonly StockService $stock) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        $woId = $event->payload['workOrderId'] ?? null;
        if (! $woId) {
            return;
        }

        // consume/release operate by wo_id and read operator_code from the reservation,
        // so no ambient operator context is required here.
        match ($event->event_type) {
            'WorkOrderFinalized' => $this->stock->consumeReservation($woId),
            'WorkOrderCancelled' => $this->stock->releaseReservation($woId),
            default => null,
        };
    }
}
