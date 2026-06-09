<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * FUL-02 order-create-install-wo step (STEP-INSTALL). Raises the installation work
 * order (WO owns field execution) and parks the order AWAITING_INSTALL — the flow
 * then waits on the 'ful-install-finalized' message (wait-install-finalize).
 */
class CreateInstallWoHandler implements TaskHandler
{
    public function __construct(
        private readonly OrderCaptureService $orders,
        private readonly WorkOrderService $workOrders,
    ) {}

    public function topic(): string
    {
        return 'order-create-install-wo';
    }

    public function label(): string
    {
        return 'Fulfillment: Create install work order';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }
        if ($order->work_order_id) { // idempotent re-run
            return TaskResult::success(['workOrderId' => $order->work_order_id]);
        }

        $wo = $this->workOrders->create([
            'type' => 'INSTALLATION',
            'kind' => 'INSTALLATION',
            'account_id' => $order->account_id,
            'customer_id' => $order->customer_id,
            'subscription_id' => $order->subscription_id,
            'homepass_id' => $order->homepass_id,
            'source_type' => 'FULFILLMENT',
            'source_ref' => $order->order_id,
            'created_by' => $order->created_by,
        ]);

        $order->update([
            'work_order_id' => $wo->work_order_id,
            'status' => FulfillmentOrder::AWAITING_INSTALL,
            'current_step' => 'INSTALL',
        ]);
        $this->orders->recordStep($order, 'INSTALL', ['workOrderId' => $wo->work_order_id]);

        return TaskResult::success(['workOrderId' => $wo->work_order_id]);
    }
}
