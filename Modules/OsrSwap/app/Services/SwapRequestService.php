<?php

namespace Modules\Osr\Swap\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Id;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Workflow\Engine\WorkflowEngine;
use Modules\Workflow\Models\ProcessInstance;
use Modules\Workflow\Models\UserTask;

/**
 * OSR-RMA-01 swap orchestration entry points. Creates the swap-request workflow
 * root and starts the config-driven osr-swap flow; feeds the field tech's
 * defect/recovery confirmation into the flow's open user task.
 */
class SwapRequestService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly WorkflowEngine $engine,
    ) {}

    /**
     * Create a swap request and start its flow.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(string $kind, array $data): EquipmentSwapRequest
    {
        $swap = EquipmentSwapRequest::query()->create([
            'swap_id' => Id::make('swp'),
            'kind' => $kind,
            'source_instance_id' => $data['source_instance_id'] ?? null,
            'target_instance_id' => $data['target_instance_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'homepass_id' => $data['homepass_id'] ?? null,
            'recovery_contractor_id' => $data['recovery_contractor_id'] ?? null,
            'flow_payload' => $data['flow_payload'] ?? [],
            'status' => EquipmentSwapRequest::CREATED,
        ]);

        $instance = $this->engine->start(
            processKey: 'osr-swap',
            businessKey: $swap->swap_id,
            variables: [
                'swapId' => $swap->swap_id,
                'kind' => $kind,
                'sourceInstanceId' => $swap->source_instance_id,
                'recoveryContractorId' => $swap->recovery_contractor_id,
            ],
            operator: $swap->operator_code,
        );
        $swap->update(['process_instance_id' => $instance->instance_id]);

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SWAP_REQUESTED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'kind' => $kind, 'sourceInstanceId' => $swap->source_instance_id],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return $swap->refresh();
    }

    /** Field tech confirms the visit outcome (defect confirmed / recovered). */
    public function confirmFieldVisit(EquipmentSwapRequest $swap, bool $defectConfirmed = true, bool $recovered = true): void
    {
        $instance = ProcessInstance::query()
            ->where('business_key', $swap->swap_id)
            ->where('status', ProcessInstance::RUNNING)
            ->latest('created_at')
            ->first();
        if (! $instance) {
            throw DomainException::conflict('No running swap flow for this request.');
        }

        $task = UserTask::query()
            ->where('instance_id', $instance->instance_id)
            ->where('status', UserTask::OPEN)
            ->latest('created_at')
            ->first();
        if (! $task) {
            throw DomainException::conflict('No open field-visit task for this swap.');
        }

        $swap->update(['status' => EquipmentSwapRequest::FIELD_VISIT_IN_PROGRESS]);
        $this->engine->completeUserTask($task, ['defectConfirmed' => $defectConfirmed, 'recovered' => $recovered]);
    }
}
