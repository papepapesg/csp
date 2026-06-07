<?php

namespace Modules\Osr\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Osr\Models\VendorRmaStub;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** OSR-RMA-01 completion. Closes the swap, records a vendor-RMA handoff stub for a defective unit. */
class CompleteSwapHandler implements TaskHandler
{
    public function __construct(private readonly EventBus $events) {}

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
            payload: ['swapId' => $swap->swap_id, 'kind' => $swap->kind, 'chargeable' => $swap->chargeable],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['swapStatus' => EquipmentSwapRequest::COMPLETED]);
    }
}
