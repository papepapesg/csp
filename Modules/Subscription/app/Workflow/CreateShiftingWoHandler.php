<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Support\Id;
use Modules\Subscription\Models\Subscription;
use Modules\WorkOrder\Services\ShiftingFlowService;
use Modules\WorkOrder\Services\WorkOrderService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-RELOCATION-01 -> WO-01 SHIFTING. Creates a SHIFTING work order for the
 * physical disconnect-at-source / reconnect-at-target move and starts its flow,
 * carrying the operation reference. The relocation commit proceeds on the BSS side;
 * the field move is tracked by the WO (DD_WO-01-CONTRACT-FOR-SUB-WF).
 */
class CreateShiftingWoHandler implements TaskHandler
{
    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly ShiftingFlowService $shifting,
    ) {}

    public function topic(): string
    {
        return 'sub.create-shifting-wo';
    }

    public function label(): string
    {
        return 'Subscription: Create SHIFTING work order';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $wo = $this->workOrders->create([
            'work_order_id' => Id::make('wo'),
            'operator_code' => $subscription->operator_code,
            'type' => 'SHIFTING',
            'kind' => 'SHIFTING',
            'job_type_code' => 'SHIFT',
            'subscription_id' => $subscription->subscription_id,
            'customer_id' => $subscription->customer_id,
            'homepass_id' => $context->var('targetHomepassId') ?? $subscription->homepass_id,
            'source_type' => 'SUBSCRIPTION_OP',
            'source_ref' => $context->var('operationId'),
            'created_by' => 'sub-relocation-flow',
        ]);
        $this->shifting->start($wo);

        return TaskResult::success(['shiftingWorkOrderId' => $wo->work_order_id]);
    }
}
