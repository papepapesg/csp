<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Events\FulfillmentEvents;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** FUL-02 order-complete step — terminal success: order COMPLETED + event. */
class CompleteOrderHandler implements TaskHandler
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function topic(): string
    {
        return 'order-complete';
    }

    public function label(): string
    {
        return 'Fulfillment: Complete order';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }

        $order->update(['status' => FulfillmentOrder::COMPLETED, 'current_step' => null, 'completed_at' => now()]);
        $this->orders->publish(FulfillmentEvents::ORDER_COMPLETED, $order, ['subscriptionId' => $order->subscription_id]);

        return TaskResult::success(['orderStatus' => FulfillmentOrder::COMPLETED]);
    }
}
