<?php

namespace Modules\WorkOrder\Workflow;

use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01-FLOW-SUPPORT wo-capture-equipment-bindings-at-finalize step. On the
 * RESOLVED path, records any equipment bindings captured during the visit and
 * emits WorkOrderEquipmentBindingsRecorded (OSR is the serial authority).
 */
class CaptureBindingsHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.capture-bindings';
    }

    public function label(): string
    {
        return 'WO: Capture equipment bindings';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $bindings = $context->var('bindings', []);
        if (! empty($bindings)) {
            $this->service->publish(WorkOrderEvents::EQUIPMENT_BINDINGS_RECORDED, $wo, ['bindings' => $bindings]);
        }

        return TaskResult::success(['bindingsCaptured' => count($bindings)]);
    }
}
