<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * FUL-02 deposit gate. When the order requires an up-front deposit
 * (depositRequired var) and it is not yet paid, the gateway routes to the
 * 'ful-payment-received' message catch and the order parks AWAITING_PAYMENT;
 * receiving the deposit correlates the message and the flow proceeds to the
 * install WO. Orders with no deposit pass straight through.
 */
class DepositGateHandler implements TaskHandler
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function topic(): string
    {
        return 'order-deposit-gate';
    }

    public function label(): string
    {
        return 'Fulfillment: Deposit gate';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }

        $depositRequired = (bool) $context->var('depositRequired', false);
        if ($depositRequired && ! $context->var('depositPaid', false)) {
            $order->update(['status' => FulfillmentOrder::AWAITING_PAYMENT, 'current_step' => 'DEPOSIT']);

            return TaskResult::success(['depositPaid' => false]);
        }

        $this->orders->recordStep($order, 'DEPOSIT', ['depositRequired' => $depositRequired]);

        return TaskResult::success(['depositPaid' => true]);
    }
}
