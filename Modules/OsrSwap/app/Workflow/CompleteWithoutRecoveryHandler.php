<?php

namespace Modules\Osr\Swap\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Models\EquipmentSku;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 EQR sub-state — Equipment Recovery refused. The customer refused to
 * return the equipment (EQP/pickup path); the swap closes
 * COMPLETED_WITHOUT_RECOVERY and the deposit is forfeited (BIL-01 charged here,
 * mirroring CompleteSwapHandler). No stock movement, the unit stays unaccounted
 * on the customer side.
 */
class CompleteWithoutRecoveryHandler implements TaskHandler
{
    public function __construct(
        private readonly EventBus $events,
        private readonly BillingIntentService $intents,
    ) {}

    public function topic(): string
    {
        return 'osr.complete-without-recovery';
    }

    public function label(): string
    {
        return 'OSR-RMA: Complete without recovery (EQR)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $swap->update(['status' => EquipmentSwapRequest::COMPLETED_WITHOUT_RECOVERY]);

        // The forfeited deposit = the unreturned unit's SKU deposit value. Charge it through
        // BIL-01 (DD_OSR-RMA-01 §8: "EQP termination triggers deposit forfeiture in EQR case").
        // Falls back to the source instance's subscription when the swap carries none.
        $instance = $swap->source_instance_id ? EquipmentInstance::query()->find($swap->source_instance_id) : null;
        $subscriptionId = $swap->subscription_id ?: $instance?->subscription_id;
        $amount = $this->resolveDepositAmount($instance);
        $swap->update(['charge_amount' => $amount]);
        if ($amount > 0 && $subscriptionId) {
            $this->intents->emit([
                'subscription_id' => $subscriptionId,
                'operator_code' => $swap->operator_code,
                'intent_type' => 'DEPOSIT_FORFEITURE',
                'amount' => $amount,
                'currency' => config('sophix.default_currency', 'KES'),
                'billing_mode' => 'POSTPAID',
                'reference' => $swap->swap_id,
            ]);
        }

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SWAP_COMPLETED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'kind' => $swap->kind, 'recovery' => false, 'depositForfeited' => true, 'depositForfeitureAmount' => $amount, 'subscriptionId' => $subscriptionId],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['swapStatus' => EquipmentSwapRequest::COMPLETED_WITHOUT_RECOVERY]);
    }

    /** The forfeited deposit = the unreturned unit's SKU deposit value. */
    private function resolveDepositAmount(?EquipmentInstance $instance): float
    {
        $sku = $instance ? EquipmentSku::query()->find($instance->sku_id) : null;

        return (float) ($sku->deposit_amount ?? 0);
    }
}
