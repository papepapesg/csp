<?php

namespace Modules\WorkOrder\Workflow;

use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01-FLOW-SHIFTING phase marker. The SHIFTING flow is multi-phase (DISCONNECT
 * at the source, RECONNECT at the target); this records the current_phase and
 * emits WorkOrderPhaseTransitioned (the real framework event SUB-WF-RELOCATION
 * parks on).
 *   config: { phase: 'RECONNECT' }
 */
class MarkPhaseHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.mark-phase';
    }

    public function label(): string
    {
        return 'WO: Mark phase';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $phase = $context->config()['phase'] ?? null;
        $from = $wo->current_phase;
        $wo->update(['current_phase' => $phase]);

        $this->service->publish(WorkOrderEvents::PHASE_TRANSITIONED, $wo, ['fromPhase' => $from, 'toPhase' => $phase]);

        return TaskResult::success(['currentPhase' => $phase]);
    }
}
