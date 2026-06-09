<?php

namespace Modules\Fulfillment\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * FUL-02 STEP-KYC resume. The customer's FINAL KYC approval (CustomerKycApproved
 * with kycStatus APPROVED) correlates 'ful-kyc-approved' for every order parked
 * AWAITING_KYC — the flow loops back through the KYC gate and proceeds.
 */
class ResumeOrderOnKycApproved
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'CustomerKycApproved' || ($event->payload['kycStatus'] ?? null) !== 'APPROVED') {
            return;
        }
        $customerId = $event->payload['customerId'] ?? null;
        if (! $customerId) {
            return;
        }

        FulfillmentOrder::query()->where('customer_id', $customerId)
            ->where('status', FulfillmentOrder::AWAITING_KYC)
            ->get()
            ->each(fn (FulfillmentOrder $order) => $this->engine->correlateMessage(
                'ful-kyc-approved', $order->order_id, ['kycConfirmed' => true],
            ));
    }
}
