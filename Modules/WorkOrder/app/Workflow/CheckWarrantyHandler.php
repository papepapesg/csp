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
 * WO-01-FLOW-SUPPORT wo-check-warranty-linkage step (§7). Looks for a prior
 * FINALIZED WO for the same customer still inside its warranty window; when found
 * for a repeat issue, records an RPT (repeat) WO linked to the prior via
 * master_wo_id and emits WorkOrderRPTLinkageRecorded. Pure read against
 * work_order.finalized_at + warranty window (no separate warranty subsystem).
 */
class CheckWarrantyHandler implements TaskHandler
{
    public function __construct(private readonly WorkOrderService $service) {}

    public function topic(): string
    {
        return 'wo.check-warranty';
    }

    public function label(): string
    {
        return 'WO: Check warranty linkage';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $wo = WorkOrder::query()->find($context->businessKey());
        if (! $wo) {
            return TaskResult::fail('Work order not found', retryable: false);
        }

        $prior = WorkOrder::query()
            ->where('operator_code', $wo->operator_code)
            ->where('customer_id', $wo->customer_id)
            ->where('work_order_id', '!=', $wo->work_order_id)
            ->where('status', WorkOrder::FINALIZED)
            ->whereNotNull('warranty_until')
            ->where('warranty_until', '>=', now())
            ->orderByDesc('finalized_at')
            ->first();

        if (! $prior) {
            return TaskResult::success(['warrantyLinked' => false]);
        }

        // Repeat-within-warranty: spawn a parallel RPT WO linked to the prior.
        $rpt = $this->service->create([
            'work_order_id' => Id::make('wo'),
            'operator_code' => $wo->operator_code,
            'type' => 'SUPPORT',
            'kind' => 'SUPPORT',
            'job_type_code' => 'RPT',
            'account_id' => $wo->account_id,
            'subscription_id' => $wo->subscription_id,
            'customer_id' => $wo->customer_id,
            'homepass_id' => $wo->homepass_id,
            'master_wo_id' => $prior->work_order_id,
            'initial_reason' => 'Repeat within warranty of '.$prior->work_order_id,
            'created_by' => 'wo-support-flow',
        ]);

        $this->service->publish(WorkOrderEvents::RPT_LINKAGE_RECORDED, $wo, [
            'rptWorkOrderId' => $rpt->work_order_id,
            'priorWorkOrderId' => $prior->work_order_id,
        ]);

        return TaskResult::success(['warrantyLinked' => true, 'rptWorkOrderId' => $rpt->work_order_id]);
    }
}
