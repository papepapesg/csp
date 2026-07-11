<?php

namespace Modules\Fulfillment\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use App\Foundation\Workflow\WorkflowRuntime;
use Modules\Fulfillment\Events\FulfillmentEvents;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * FUL-02 order capture. The journey itself is CONFIG, not code: capture() creates
 * the order row and starts the `ful-order-capture` process definition (FUL-02-
 * FRAMEWORK §1.1); the steps — validate, create-subscription, create-install-wo,
 * await-install, KYC gate, trigger-activation, complete — run as external-task
 * topics seeded as data and editable in the Workflow Studio. This service only
 * owns the order row and the desk-facing signals (manual install confirm, cancel).
 */
class OrderCaptureService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly WorkflowRuntime $engine,
    ) {}

    /**
     * Capture an order and start its journey flow. The flow's workers create the
     * subscription + install WO; the order returns immediately as CAPTURED.
     *
     * @param  array<string,mixed>  $data
     */
    public function capture(array $data): FulfillmentOrder
    {
        $order = DB::transaction(function () use ($data) {
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

            $this->recordStep($order, 'CAPTURE');
            if (! empty($data['payment_ref'])) {
                $this->recordStep($order, 'PAYMENT', ['paymentRef' => $data['payment_ref']]);
            }

            $this->publish(FulfillmentEvents::ORDER_CAPTURED, $order, []);

            return $order;
        });

        // Start the data-defined journey (FUL-02-FRAMEWORK §1.1); store the instance id.
        $instance = $this->engine->start(
            processKey: 'ful-order-capture',
            businessKey: $order->order_id,
            variables: [
                'orderId' => $order->order_id,
                'customerId' => $order->customer_id,
                'accountId' => $order->account_id,
                'packageRef' => $order->package_ref,
                'homepassId' => $order->homepass_id,
                'billingMode' => $order->billing_mode,
                // Deposit gate: a deposit-required order parks AWAITING_PAYMENT
                // until the deposit is confirmed (a paid order passes through).
                'depositRequired' => (bool) ($data['deposit_required'] ?? false),
                'depositPaid' => ! empty($data['payment_ref']),
            ],
            operator: $order->operator_code,
        );
        $order->update(['process_instance_id' => $instance->instance_id]);

        return $order->refresh()->load('steps');
    }

    /**
     * Desk confirmation that the install is done: correlates the flow's
     * 'ful-install-finalized' message catch (the WorkOrderFinalized event does the
     * same automatically). The journey then runs the KYC gate + activation.
     */
    public function complete(FulfillmentOrder $order): FulfillmentOrder
    {
        if ($order->status === FulfillmentOrder::COMPLETED) {
            return $order; // idempotent
        }
        if (! in_array($order->status, [FulfillmentOrder::AWAITING_INSTALL, FulfillmentOrder::CAPTURED], true)) {
            throw DomainException::conflict("Order is {$order->status}; nothing to confirm.");
        }

        $this->engine->correlateMessage('ful-install-finalized', $order->order_id, ['installConfirmed' => true]);

        return $order->refresh()->load('steps');
    }

    /** Deposit received: resume an order parked AWAITING_PAYMENT (FUL-02 wait-payment). */
    public function confirmDepositPaid(FulfillmentOrder $order): FulfillmentOrder
    {
        $this->engine->correlateMessage('ful-payment-received', $order->order_id, ['depositPaid' => true]);

        return $order->refresh()->load('steps');
    }

    public function cancel(FulfillmentOrder $order, ?string $reason = null): FulfillmentOrder
    {
        if (in_array($order->status, [FulfillmentOrder::COMPLETED, FulfillmentOrder::CANCELLED], true)) {
            throw DomainException::conflict('Order can no longer be cancelled.');
        }

        // FUL-02-FRAMEWORK §1.8: cancellation interrupts the journey wherever it is.
        $this->engine->cancelByBusinessKey($order->order_id, $reason ?? 'Fulfillment order cancelled');

        // FUL-02-STEP-CANCELLATION: compensate the artifacts the journey already created,
        // in reverse creation order. Each step is conditional — a step that never ran left
        // nothing to undo. Without this a cancelled order leaked a dispatched install WO and
        // a half-built subscription.
        $this->compensate($order, $reason);

        $order->update(['status' => FulfillmentOrder::CANCELLED, 'current_step' => null]);
        $this->publish(FulfillmentEvents::ORDER_CANCELLED, $order, ['reason' => $reason]);

        return $order;
    }

    /** Undo the downstream side-effects of a cancelled order (cancel install WO, terminate subscription). */
    private function compensate(FulfillmentOrder $order, ?string $reason): void
    {
        $cancellable = [
            WorkOrder::PENDING,
            WorkOrder::ASSIGNED,
            WorkOrder::IN_PROGRESS,
            WorkOrder::FINALIZATION_PENDING,
        ];
        if ($order->work_order_id
            && ($wo = WorkOrder::query()->find($order->work_order_id))
            && in_array($wo->status, $cancellable, true)) {
            app(WorkOrderService::class)->cancel($wo, $reason ?? 'Fulfillment order cancelled');
        }

        if ($order->subscription_id
            && ($sub = Subscription::query()->find($order->subscription_id))
            && $sub->status_code !== Subscription::TERMINATED) {
            app(SubscriptionService::class)->transitionStatus($sub, Subscription::TERMINATED);
        }
    }

    /** Step ledger writer used by the journey's task handlers. @param array<string,mixed> $result */
    public function recordStep(FulfillmentOrder $order, string $step, array $result = [], string $status = 'DONE'): void
    {
        $order->steps()->create([
            'step' => $step,
            'status' => $status,
            'result' => $result ?: null,
            'completed_at' => now(),
        ]);
        $this->publish(FulfillmentEvents::ORDER_STEP_COMPLETED, $order, ['step' => $step, 'stepStatus' => $status]);
    }

    /** @param array<string,mixed> $payload */
    public function publish(string $type, FulfillmentOrder $order, array $payload): void
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
