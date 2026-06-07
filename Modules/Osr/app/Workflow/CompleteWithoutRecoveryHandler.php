<?php

namespace Modules\Osr\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 EQR sub-state — Equipment Recovery refused. The customer refused to
 * return the equipment (EQP/pickup path); the swap closes
 * COMPLETED_WITHOUT_RECOVERY and the deposit is forfeited (BIL-01 consumes the
 * event). No stock movement, the unit stays unaccounted on the customer side.
 */
class CompleteWithoutRecoveryHandler implements TaskHandler
{
    public function __construct(private readonly EventBus $events) {}

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

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SWAP_COMPLETED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'kind' => $swap->kind, 'recovery' => false, 'depositForfeited' => true],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['swapStatus' => EquipmentSwapRequest::COMPLETED_WITHOUT_RECOVERY]);
    }
}
