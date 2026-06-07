<?php

namespace Modules\WorkOrder\Workflow;

use App\Foundation\Support\Id;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Events\WorkOrderEvents;
use Modules\WorkOrder\Models\WorkOrder;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * WO-01-FLOW-SUPPORT Pattern A escalation (wo-mark-escalation-candidate +
 * QCS spawn). The original Support WO finalizes as could-not-resolve; a new QCS
 * (Quality Control Service) WO is created for the internal NOC maintenance team,
 * linked via master_wo_id, so the NOC has its own trackable work item.
 */
class MarkEscalationHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.mark-escalation';
    }

    public function label(): string
    {
        return 'WO: Mark escalation candidate (spawn QCS)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $wo->update(['escalation_candidate' => true]);

        // Pattern A: spawn a new QCS WO for the internal NOC maintenance team.
        $qcs = $this->service->create([
            'work_order_id' => Id::make('wo'),
            'operator_code' => $wo->operator_code,
            'type' => 'NOC',
            'kind' => 'SUPPORT',
            'job_type_code' => 'QCS',
            'account_id' => $wo->account_id,
            'subscription_id' => $wo->subscription_id,
            'customer_id' => $wo->customer_id,
            'homepass_id' => $wo->homepass_id,
            'team_id' => 'ctr_NOC_MAINT',
            'master_wo_id' => $wo->work_order_id,
            'initial_reason' => 'Escalated from '.$wo->work_order_id,
            'created_by' => 'wo-support-flow',
        ]);

        $this->service->publish(WorkOrderEvents::ESCALATION_CANDIDATE, $wo, [
            'qcsWorkOrderId' => $qcs->work_order_id,
            'finalReason' => $context->var('finalReason'),
        ]);

        return TaskResult::success(['qcsWorkOrderId' => $qcs->work_order_id]);
    }
}
