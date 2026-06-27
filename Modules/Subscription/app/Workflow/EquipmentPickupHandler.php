<?php

namespace Modules\Subscription\Workflow;

use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Swap\Services\SwapRequestService;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-TERMINATE-01 -> OSR-RMA EQP. On termination, raise an Equipment Pickup
 * (EQP) swap-request for each field-active serialized device bound to the
 * subscription, so the contractor retrieves it. Cross-module call to OSR-RMA
 * (the swap flow then drives the pickup + recovery routing).
 */
class EquipmentPickupHandler implements TaskHandler
{
    public function __construct(private readonly SwapRequestService $swaps) {}

    public function topic(): string
    {
        return 'sub.trigger-equipment-pickup';
    }

    public function label(): string
    {
        return 'Subscription: Trigger equipment pickup (EQP)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $instances = EquipmentInstance::query()
            ->where('operator_code', $subscription->operator_code)
            ->where('subscription_id', $subscription->subscription_id)
            ->where('state', EquipmentInstance::IN_FIELD_ACTIVE)
            ->get();

        $created = [];
        foreach ($instances as $instance) {
            $swap = $this->swaps->create('EQP', [
                'source_instance_id' => $instance->instance_id,
                'subscription_id' => $subscription->subscription_id,
                'customer_id' => $subscription->customer_id,
                'homepass_id' => $subscription->homepass_id,
                'recovery_contractor_id' => $context->var('recoveryContractorId', 'ctr_default'),
            ]);
            $created[] = $swap->swap_id;
        }

        return TaskResult::success(['equipmentPickupSwaps' => $created]);
    }
}
