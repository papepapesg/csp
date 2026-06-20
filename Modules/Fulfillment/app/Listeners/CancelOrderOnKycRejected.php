<?php

namespace Modules\Fulfillment\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;

/**
 * FUL-02 STEP-KYC failure path. The KYC gate's BPMN only has an approval loop (await
 * 'ful-kyc-approved'), so a customer whose final KYC is REJECTED left the order parked
 * AWAITING_KYC forever. A rejected KYC means the order cannot proceed — cancel it, which
 * interrupts the journey and compensates the install WO + subscription it already created.
 */
class CancelOrderOnKycRejected
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'CustomerKycRejected') {
            return;
        }
        $customerId = $event->payload['customerId'] ?? null;
        if (! $customerId) {
            return;
        }

        FulfillmentOrder::query()->where('customer_id', $customerId)
            ->where('status', FulfillmentOrder::AWAITING_KYC)
            ->get()
            ->each(fn (FulfillmentOrder $order) => $this->orders->cancel($order, 'KYC rejected'));
    }
}
