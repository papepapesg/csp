<?php

namespace Modules\Osr\Swap\Workflow;

use App\Foundation\Support\Id;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 slot reservation (EM-02 contractor-slot-commitments). v1.0 stub:
 * atomically reserves a contractor slot in the customer's tech region. Released on
 * cancellation.
 */
class ReserveSlotHandler implements TaskHandler
{
    public function topic(): string
    {
        return 'osr.reserve-slot';
    }

    public function label(): string
    {
        return 'OSR-RMA: Reserve contractor slot';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $slotId = Id::make('slot');
        $swap->update(['slot_commitment_id' => $slotId, 'status' => EquipmentSwapRequest::AWAITING_SLOT]);

        return TaskResult::success(['slotCommitmentId' => $slotId]);
    }
}
