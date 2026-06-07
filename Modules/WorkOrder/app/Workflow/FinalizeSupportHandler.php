<?php

namespace Modules\WorkOrder\Workflow;

use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WoJobTypeCatalog;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01-FLOW-SUPPORT common close (wo-enforce-finalize-checklist +
 * wo-emit-state-event + wo-tag-warranty-window + wo-emit-support-completed).
 * Drives the WO to its terminal state, stamps the warranty window for future
 * repeat-linkage, and emits the rich WorkOrderSupportCompleted event (Finance /
 * RPT linkage consumer).
 */
class FinalizeSupportHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.finalize-support';
    }

    public function label(): string
    {
        return 'WO: Finalize support (complete)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $finalReason = $context->var('finalReason', 'RESOLVED');
        $job = WoJobTypeCatalog::query()
            ->where('operator_code', $wo->operator_code)
            ->where('job_type_code', $wo->job_type_code)
            ->first();
        $warrantyDays = $job?->warranty_days ?? 90;

        $wo = $this->service->completeSupport($wo, [
            'final_reason' => $finalReason,
            'current_phase' => null,
            'warranty_until' => now()->addDays($warrantyDays),
        ], 'wo-support-flow');

        $this->service->publish(WorkOrderEvents::SUPPORT_COMPLETED, $wo, [
            'finalReason' => $finalReason,
            'jobTypeCode' => $wo->job_type_code,
            'escalationCandidate' => (bool) $wo->escalation_candidate,
            'masterWoId' => $wo->master_wo_id,
        ]);

        return TaskResult::success(['workOrderStatus' => WorkOrder::FINALIZED, 'finalReason' => $finalReason]);
    }
}
