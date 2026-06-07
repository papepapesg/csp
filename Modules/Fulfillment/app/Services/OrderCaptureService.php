<?php

namespace Modules\Fulfillment\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Fulfillment\Events\FulfillmentEvents;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\OperationFramework;
use Modules\Subscription\Services\SubscriptionService;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * FUL-02 onboarding orchestration (happy path). Coordinates fulfillment
 * readiness before activation, then hands ownership back to each owning module:
 * SUB-LM owns the subscription, WO owns field execution, BIL owns payment.
 *
 * Cross-module coordination is via the owning modules' SERVICES (not their
 * tables) — the modular-monolith equivalent of calling owning-module APIs.
 */
class OrderCaptureService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly SubscriptionService $subscriptions,
        private readonly OperationFramework $operations,
        private readonly WorkOrderService $workOrders,
    ) {}

    /**
     * Capture an order and provision its happy path: create the (pending)
     * subscription and an installation work order.
     *
     * @param  array<string,mixed>  $data
     */
    public function capture(array $data): FulfillmentOrder
    {
        return DB::transaction(function () use ($data) {
            $order = FulfillmentOrder::query()->create([
                'customer_id' => $data['customer_id'],
                'account_id' => $data['account_id'],
                'homepass_id' => $data['homepass_id'] ?? null,
                'package_ref' => $data['package_ref'],
                'package_version_id' => $data['package_version_id'] ?? null,
                'billing_mode' => $data['billing_mode'] ?? 'POSTPAID',
                'status' => FulfillmentOrder::CAPTURED,
                'current_step' => 'CAPTURE',
                'payment_ref' => $data['payment_ref'] ?? null,
                'created_by' => $data['created_by'] ?? null,
            ]);

            $this->completeStep($order, 'CAPTURE');
            $this->completeStep($order, 'VALIDATE');
            if (! empty($data['payment_ref'])) {
                $this->completeStep($order, 'PAYMENT', ['paymentRef' => $data['payment_ref']]);
            }

            // STEP-SUBSCRIPTION — create the subscription (SUB-LM owns it).
            $subscription = $this->subscriptions->create([
                'customer_id' => $order->customer_id,
                'account_id' => $order->account_id,
                'homepass_id' => $order->homepass_id ?? 'hp_unknown',
                'package_ref' => $order->package_ref,
                'package_version_id' => $order->package_version_id,
                'billing_mode' => $order->billing_mode,
                'created_by' => $order->created_by,
            ]);
            $this->completeStep($order, 'SUBSCRIPTION', ['subscriptionId' => $subscription->subscription_id]);

            // STEP-INSTALL — raise an installation work order (WO owns field exec).
            $workOrder = $this->workOrders->create([
                'type' => 'INSTALLATION',
                'account_id' => $order->account_id,
                'customer_id' => $order->customer_id,
                'subscription_id' => $subscription->subscription_id,
                'homepass_id' => $order->homepass_id,
                'source_type' => 'FULFILLMENT',
                'source_ref' => $order->order_id,
                'created_by' => $order->created_by,
            ]);

            $order->update([
                'subscription_id' => $subscription->subscription_id,
                'work_order_id' => $workOrder->work_order_id,
                'status' => FulfillmentOrder::AWAITING_INSTALL,
                'current_step' => 'INSTALL',
            ]);

            $this->emit(FulfillmentEvents::ORDER_CAPTURED, $order, [
                'subscriptionId' => $subscription->subscription_id,
                'workOrderId' => $workOrder->work_order_id,
            ]);

            return $order->refresh()->load('steps');
        });
    }

    /**
     * FUL-03 service activation: once install is done, activate the subscription
     * via SUB-WF and complete the order.
     */
    public function complete(FulfillmentOrder $order): FulfillmentOrder
    {
        if ($order->status === FulfillmentOrder::COMPLETED) {
            return $order; // idempotent
        }
        if (! $order->subscription_id) {
            throw DomainException::conflict('Order has no subscription to activate.');
        }

        $subscription = Subscription::query()->findOrFail($order->subscription_id);

        $this->completeStep($order, 'INSTALL');
        $order->update(['status' => FulfillmentOrder::ACTIVATING, 'current_step' => 'ACTIVATION']);

        // STEP-ACTIVATION — trigger SUB-WF activation (idempotent by order id).
        $this->operations->trigger(
            subscription: $subscription,
            kind: 'ACTIVATE',
            input: ['orderId' => $order->order_id],
            idempotencyKey: 'ful-activate-'.$order->order_id,
        );

        return DB::transaction(function () use ($order) {
            $this->completeStep($order, 'ACTIVATION');
            $order->update([
                'status' => FulfillmentOrder::COMPLETED,
                'current_step' => null,
                'completed_at' => now(),
            ]);
            $this->emit(FulfillmentEvents::ORDER_COMPLETED, $order, ['subscriptionId' => $order->subscription_id]);

            return $order->refresh()->load('steps');
        });
    }

    public function cancel(FulfillmentOrder $order, ?string $reason = null): FulfillmentOrder
    {
        if (in_array($order->status, [FulfillmentOrder::COMPLETED, FulfillmentOrder::CANCELLED], true)) {
            throw DomainException::conflict('Order can no longer be cancelled.');
        }

        $order->update(['status' => FulfillmentOrder::CANCELLED, 'current_step' => null]);
        $this->emit(FulfillmentEvents::ORDER_CANCELLED, $order, ['reason' => $reason]);

        return $order;
    }

    /** @param array<string,mixed> $result */
    private function completeStep(FulfillmentOrder $order, string $step, array $result = []): void
    {
        $order->steps()->create([
            'step' => $step,
            'status' => 'DONE',
            'result' => $result ?: null,
            'completed_at' => now(),
        ]);
        $this->emit(FulfillmentEvents::ORDER_STEP_COMPLETED, $order, ['step' => $step]);
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $type, FulfillmentOrder $order, array $payload): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: FulfillmentEvents::TOPIC,
            payload: array_merge(['orderId' => $order->order_id, 'accountId' => $order->account_id], $payload),
            aggregateType: 'FulfillmentOrder',
            aggregateId: $order->order_id,
        ));
    }
}
