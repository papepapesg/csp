<?php

namespace Modules\WorkOrder\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\WorkOrder\Models\FieldAuditTask;
use Modules\WorkOrder\Services\WorkOrderService;

/**
 * FA-01 §6 step 4 (DEV guide): when a field-audit task is created with createWorkOrder=true,
 * FA-01 emits FieldAuditWorkOrderRequested and WO-01 creates the field-execution work order
 * (kind=FIELD_AUDIT), then FA-01 stores the wo_id and moves the task to ASSIGNED. This was an
 * orphan event — published but never consumed, so a WO-backed audit never got its work order.
 * Idempotent: a task that already carries a wo_id is skipped (event replay safe).
 */
class CreateFieldAuditWorkOrder
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function handle(OutboxEventPublished $published): void
    {
        if ($published->event->event_type !== 'FieldAuditWorkOrderRequested') {
            return;
        }
        $task = FieldAuditTask::query()->find($published->event->payload['aggregateId'] ?? null);
        if (! $task || $task->wo_id) {
            return; // unknown task, or a WO was already created.
        }

        $wo = $this->workOrders->create([
            'operator_code' => $task->operator_code,
            'type' => 'FIELD_AUDIT',
            'priority' => 'NORMAL',
            'account_id' => $task->account_id,
            'subscription_id' => $task->subscription_id,
            'customer_id' => $task->customer_id,
            'homepass_id' => $task->homepass_id,
            'source_type' => 'FIELD_AUDIT',
            'source_ref' => $task->audit_task_id,
        ]);

        // FA-01 stores the wo_id and moves the task to ASSIGNED (now dispatchable via WO-01).
        $task->update(['wo_id' => $wo->work_order_id, 'status' => FieldAuditTask::ASSIGNED]);
    }
}
