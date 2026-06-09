<?php

namespace Modules\Fulfillment\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * FUL-02 wait-install-finalize. When the order's installation WO finalizes,
 * correlate 'ful-install-finalized' so the parked order flow proceeds to the KYC
 * gate + activation. Idempotent: only orders still AWAITING_INSTALL match.
 */
class ResumeOrderOnInstallFinalized
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'WorkOrderFinalized') {
            return;
        }
        $woId = $event->payload['workOrderId'] ?? null;
        if (! $woId) {
            return;
        }

        FulfillmentOrder::query()->where('work_order_id', $woId)
            ->where('status', FulfillmentOrder::AWAITING_INSTALL)
            ->get()
            ->each(fn (FulfillmentOrder $order) => $this->engine->correlateMessage(
                'ful-install-finalized', $order->order_id, ['installConfirmed' => true],
            ));
    }
}
