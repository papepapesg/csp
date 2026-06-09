<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * FUL-02 order-validate step. Sanity-checks the captured order (package + serviceable
 * address present) and routes the flow via the eligibility gateway. An ineligible
 * order is marked CANCELLED with a FAILED VALIDATE step (rejection end).
 */
class ValidateOrderHandler implements TaskHandler
{
    public function __construct(private readonly OrderCaptureService $orders) {}

    public function topic(): string
    {
        return 'order-validate';
    }

    public function label(): string
    {
        return 'Fulfillment: Validate order';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }

        $eligible = filled($order->package_ref) && filled($order->homepass_id ?? $context->var('homepassId'));
        if (! $eligible) {
            $order->update(['status' => FulfillmentOrder::CANCELLED, 'current_step' => null]);
            $this->orders->recordStep($order, 'VALIDATE', ['reason' => 'MISSING_PACKAGE_OR_HOMEPASS'], 'FAILED');

            return TaskResult::success(['eligible' => false]);
        }

        $this->orders->recordStep($order, 'VALIDATE');

        return TaskResult::success(['eligible' => true]);
    }
}
