<?php

namespace Modules\Osr\Workflow;

use App\Foundation\Support\Id;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * OSR-RMA-01 WO creation. Creates a field-visit Work Order (WO-01) with the
 * swap-specific job type (EQUIPMENT_SWAP / PICKUP / UPGRADE), origin_ref =
 * swap_id. WO finalization fires a message back into the swap flow.
 */
class CreateSwapWorkOrderHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function topic(): string
    {
        return 'osr.create-swap-wo';
    }

    public function label(): string
    {
        return 'OSR-RMA: Create field-visit work order';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $wo = $this->workOrders->create([
            'work_order_id' => Id::make('wo'),
            'operator_code' => $swap->operator_code,
            'type' => 'EQUIPMENT',
            'kind' => 'SHIFTING',
            'job_type_code' => 'EQUIPMENT_SWAP',
            'account_id' => null,
            'subscription_id' => $swap->subscription_id,
            'customer_id' => $swap->customer_id,
            'homepass_id' => $swap->homepass_id,
            'contractor_id' => $swap->recovery_contractor_id,
            'source_type' => 'MANUAL',
            'source_ref' => $swap->swap_id,
            'created_by' => 'osr-rma-flow',
        ]);

        $swap->update(['work_order_id' => $wo->work_order_id, 'status' => EquipmentSwapRequest::WO_CREATED]);

        return TaskResult::success(['workOrderId' => $wo->work_order_id]);
    }
}
