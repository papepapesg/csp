<?php

namespace Modules\WorkOrder\Services;

use App\Foundation\Errors\DomainException;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;
use Modules\WorkOrder\Models\WoFlowConfig;
use Modules\WorkOrder\Models\WorkOrder;

/**
 * WO-01-FLOW-SUPPORT orchestration entry points. Starts the config-driven support
 * flow for a WO (process key resolved from wo_flow_config, so a market swaps the
 * flow as config) and feeds the field/desk agent's resolution into the open user
 * task, after which the engine runs the resolution gate + close.
 */
class SupportFlowService
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /** Start the support flow for a WO and return the process instance. */
    public function start(WorkOrder $wo): ProcessInstance
    {
        $config = WoFlowConfig::query()
            ->where('operator_code', $wo->operator_code)
            ->where('kind', $wo->kind ?? 'SUPPORT')
            ->first();
        $processKey = $config?->bpmn_process_key ?? 'wo-support';

        return $this->engine->start(
            processKey: $processKey,
            businessKey: $wo->work_order_id,
            variables: [
                'workOrderId' => $wo->work_order_id,
                'customerId' => $wo->customer_id,
                'jobTypeCode' => $wo->job_type_code,
                'kind' => $wo->kind ?? 'SUPPORT',
            ],
            operator: $wo->operator_code,
        );
    }

    /**
     * Field/desk agent supplies the resolution outcome, completing the flow's
     * open await-resolution user task.
     *
     * @param  array<int,mixed>  $bindings
     */
    public function resolve(WorkOrder $wo, string $finalReason, array $bindings = []): void
    {
        $instance = ProcessInstance::query()
            ->where('business_key', $wo->work_order_id)
            ->where('status', ProcessInstance::RUNNING)
            ->latest('created_at')
            ->first();
        if (! $instance) {
            throw DomainException::conflict('No running support flow for this work order.');
        }

        $task = UserTask::query()
            ->where('instance_id', $instance->instance_id)
            ->where('status', UserTask::OPEN)
            ->latest('created_at')
            ->first();
        if (! $task) {
            throw DomainException::conflict('No open resolution task for this work order.');
        }

        $this->engine->completeUserTask($task, ['finalReason' => $finalReason, 'bindings' => $bindings]);
    }
}
