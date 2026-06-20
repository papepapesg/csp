<?php

namespace Modules\Fulfillment\Workflow;

use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Services\AccountService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\OperationFramework;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * FUL-02 order-trigger-activation step (STEP-ACTIVATION / FUL-03). Triggers the
 * SUB-WF ACTIVATE operation (idempotent by order id) — the subscription's own
 * config-driven activation flow takes it from there.
 */
class TriggerActivationHandler implements TaskHandler
{
    public function __construct(
        private readonly OrderCaptureService $orders,
        private readonly OperationFramework $operations,
        private readonly AccountService $accounts,
    ) {}

    public function topic(): string
    {
        return 'order-trigger-activation';
    }

    public function label(): string
    {
        return 'Fulfillment: Trigger activation';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $order = FulfillmentOrder::query()->find($context->var('orderId'));
        if (! $order || ! $order->subscription_id) {
            return TaskResult::fail('Order has no subscription to activate', retryable: false);
        }

        // R-ILM-F-4: a provisioning-blocking account flag (e.g. FRAUD_SUSPECTED) blocks
        // new service activation until it is cleared. Non-retryable — needs BO intervention.
        $account = $order->account_id ? CustomerAccount::query()->where('account_id', $order->account_id)->first() : null;
        if ($account && $this->accounts->hasProvisioningBlockingFlag($account)) {
            return TaskResult::fail('Activation blocked by a provisioning-affecting account flag (e.g. FRAUD_SUSPECTED).', retryable: false);
        }

        $order->update(['status' => FulfillmentOrder::ACTIVATING, 'current_step' => 'ACTIVATION']);

        $subscription = Subscription::query()->findOrFail($order->subscription_id);
        $operation = $this->operations->trigger(
            subscription: $subscription,
            kind: 'ACTIVATE',
            input: ['orderId' => $order->order_id],
            idempotencyKey: 'ful-activate-'.$order->order_id,
        );

        $this->orders->recordStep($order, 'ACTIVATION', ['operationId' => $operation->operation_id]);

        return TaskResult::success(['activationOperationId' => $operation->operation_id]);
    }
}
