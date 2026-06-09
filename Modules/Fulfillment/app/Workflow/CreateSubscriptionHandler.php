<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * FUL-02 order-create-subscription step (STEP-SUBSCRIPTION). Creates the pending
 * subscription via SUB-LM's service (SUB owns it) and links it onto the order.
 */
class CreateSubscriptionHandler implements TaskHandler
{
    public function __construct(
        private readonly OrderCaptureService $orders,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function topic(): string
    {
        return 'order-create-subscription';
    }

    public function label(): string
    {
        return 'Fulfillment: Create subscription';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order) {
            return TaskResult::fail('Order not found', retryable: false);
        }
        if ($order->subscription_id) { // idempotent re-run
            return TaskResult::success(['subscriptionId' => $order->subscription_id]);
        }

        $subscription = $this->subscriptions->create([
            'customer_id' => $order->customer_id,
            'account_id' => $order->account_id,
            'homepass_id' => $order->homepass_id ?? 'hp_unknown',
            'package_ref' => $order->package_ref,
            'package_version_id' => $order->package_version_id,
            'billing_mode' => $order->billing_mode,
            'created_by' => $order->created_by,
        ]);

        $order->update(['subscription_id' => $subscription->subscription_id, 'current_step' => 'SUBSCRIPTION']);
        $this->orders->recordStep($order, 'SUBSCRIPTION', ['subscriptionId' => $subscription->subscription_id]);

        return TaskResult::success(['subscriptionId' => $subscription->subscription_id]);
    }
}
