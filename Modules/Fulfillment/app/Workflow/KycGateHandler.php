<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Models\Customer;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * FUL-02 STEP-KYC gate. Activation requires the customer's KYC to be APPROVED
 * (ILM-CFG-01). Not approved -> the gateway routes to the 'ful-kyc-approved'
 * message catch and the order parks AWAITING_KYC; the final KYC approval event
 * correlates the message and the flow loops back through this gate.
 */
class KycGateHandler implements TaskHandler
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function topic(): string
    {
        return 'order-kyc-gate';
    }

    public function label(): string
    {
        return 'Fulfillment: KYC gate';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }

        $customer = Customer::query()->find($order->customer_id);
        $approved = ! $customer || $customer->kyc_status === 'APPROVED';

        if (! $approved) {
            $order->update(['status' => FulfillmentOrder::AWAITING_KYC, 'current_step' => 'KYC']);

            return TaskResult::success(['kycApproved' => false]);
        }

        $this->orders->recordStep($order, 'KYC', ['kycStatus' => $customer?->kyc_status ?? 'UNVERIFIED']);

        return TaskResult::success(['kycApproved' => true]);
    }
}
