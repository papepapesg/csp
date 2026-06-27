<?php

namespace Modules\Osr\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Modules\Billing\Intent\Services\BillingIntentService;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Models\EquipmentSku;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Osr\Models\VendorRmaStub;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** OSR-RMA-01 completion. Closes the swap, records a vendor-RMA handoff stub for a defective unit. */
class CompleteSwapHandler implements TaskHandler
{
    public function __construct(
        private readonly EventBus $events,
        private readonly BillingIntentService $intents,
    ) {}

    public function topic(): string
    {
        return 'osr.complete-swap';
    }

    public function label(): string
    {
        return 'OSR-RMA: Complete swap';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $swap->update(['status' => EquipmentSwapRequest::COMPLETED]);

        // A chargeable swap (out-of-warranty / upgrade) raises the fee through BIL-01 —
        // the charge decision was made by ValidateSwapEligibilityHandler; here it is
        // actually billed. Amount = the swapped device's equipment value (SKU deposit).
        if ($swap->chargeable && $swap->subscription_id && $swap->charge_code) {
            $amount = $this->resolveChargeAmount($swap);
            $swap->update(['charge_amount' => $amount]);
            if ($amount > 0) {
                $this->intents->emit([
                    'subscription_id' => $swap->subscription_id,
                    'operator_code' => $swap->operator_code,
                    'intent_type' => $swap->charge_code,        // OUT_OF_WARRANTY | UPGRADE_FEE
                    'amount' => $amount,
                    'currency' => config('sophix.default_currency', 'KES'),
                    'billing_mode' => 'POSTPAID',
                    'reference' => $swap->swap_id,
                ]);
            }
        }

        // Defective recovered units are batched to the vendor (v1.0 STUB).
        if ($context->var('defectConfirmed')) {
            VendorRmaStub::query()->create([
                'id' => Id::make('vrma'),
                'operator_code' => $swap->operator_code,
                'swap_id' => $swap->swap_id,
                'source_instance_id' => $swap->source_instance_id,
                'batch_ref' => 'PENDING_BATCH',
            ]);
        }

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SWAP_COMPLETED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'kind' => $swap->kind, 'chargeable' => $swap->chargeable, 'chargeAmount' => (float) ($swap->charge_amount ?? 0)],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['swapStatus' => EquipmentSwapRequest::COMPLETED]);
    }

    /** The equipment value to charge for a kept/lost/upgraded device = the SKU deposit. */
    private function resolveChargeAmount(EquipmentSwapRequest $swap): float
    {
        $instance = $swap->source_instance_id ? EquipmentInstance::query()->find($swap->source_instance_id) : null;
        $sku = $instance ? EquipmentSku::query()->find($instance->sku_id) : null;

        return (float) ($sku->deposit_amount ?? 0);
    }
}
