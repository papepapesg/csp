<?php

namespace Modules\WorkOrder\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Workflow\WorkflowRuntime;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * WO-01-FLOW-SHIFTING orchestration. Runs the multi-phase shifting flow (disconnect
 * at source, reconnect at target) for a SHIFTING work order; each phase's field
 * step is an open user task the tech completes via advance().
 */
class ShiftingFlowService
{
    public function __construct(private readonly WorkflowRuntime $engine) {}

    public function start(WorkOrder $wo): ProcessInstance
    {
        return $this->engine->start(
            processKey: 'wo-shifting',
            businessKey: $wo->work_order_id,
            variables: ['workOrderId' => $wo->work_order_id, 'kind' => 'SHIFTING'],
            operator: $wo->operator_code,
        );
    }

    /** Tech completes the current phase's field step. */
    public function advance(WorkOrder $wo, array $outputs = []): void
    {
        $instance = ProcessInstance::query()
            ->where('business_key', $wo->work_order_id)
            ->where('status', ProcessInstance::RUNNING)
            ->latest('created_at')
            ->first();
        if (! $instance) {
            throw DomainException::conflict('No running shifting flow for this work order.');
        }

        $task = UserTask::query()
            ->where('instance_id', $instance->instance_id)
            ->where('status', UserTask::OPEN)
            ->latest('created_at')
            ->first();
        if (! $task) {
            throw DomainException::conflict('No open phase task for this work order.');
        }

        $this->engine->completeUserTask($task, $outputs);
    }
}
