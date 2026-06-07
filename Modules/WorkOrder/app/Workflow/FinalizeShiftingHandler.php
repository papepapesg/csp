<?php

namespace Modules\WorkOrder\Workflow;

use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01-FLOW-SHIFTING close. Finalizes the shifting WO after both phases complete
 * and emits WorkOrderShiftingCompleted (which the owning SUB-WF-RELOCATION flow
 * consumes as the WO-completion signal).
 */
class FinalizeShiftingHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.finalize-shifting';
    }

    public function label(): string
    {
        return 'WO: Finalize shifting';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $wo = $this->service->completeSupport($wo, ['final_reason' => 'RECONNECTED', 'current_phase' => 'DONE'], 'wo-shifting-flow');
        $this->service->publish(WorkOrderEvents::SHIFTING_COMPLETED, $wo, ['masterWoId' => $wo->master_wo_id]);

        return TaskResult::success(['workOrderStatus' => WorkOrder::FINALIZED]);
    }
}
