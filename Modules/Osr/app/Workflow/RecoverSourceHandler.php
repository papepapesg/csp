<?php

namespace Modules\Osr\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\EquipmentInstance;
use Modules\Osr\Models\EquipmentSwapRequest;
use Modules\Osr\Models\StockLocation;
use Modules\Osr\Services\EquipmentInstanceService;
use Modules\Osr\Services\StockService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * OSR-RMA-01 source recovery — THE ROUTING FIX. When the tech removes the old
 * device, this routes the recovered unit back to the RECOVERING CONTRACTOR's
 * warehouse (their van), not the main warehouse — closing the documented ~40+
 * stale-equipment gap. The routing-decision-recovered-instance-routes-to-recovering
 * -contractor rule (rules.osr.recovered-routing) is the explicit codification. Writes
 * both the OSR-INSTANCE lifecycle event and the OSR-01 stock movement.
 */
class RecoverSourceHandler implements TaskHandler
{
    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly EquipmentInstanceService $instances,
        private readonly StockService $stock,
    ) {}

    public function topic(): string
    {
        return 'osr.recover-source';
    }

    public function label(): string
    {
        return 'OSR-RMA: Recover source (route to contractor)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap || ! $swap->source_instance_id) {
            return TaskResult::fail('Swap request / source not found', retryable: false);
        }

        $source = EquipmentInstance::query()->find($swap->source_instance_id);
        if (! $source) {
            return TaskResult::fail('Source instance not found', retryable: false);
        }

        $contractorId = $swap->recovery_contractor_id;

        // Routing decision: recovered unit goes back to the recovering contractor.
        $routing = $this->rules->evaluate('rules.osr.recovered-routing', ['recoveryContractorId' => $contractorId]);
        $routeToContractor = $routing['routeToContractor'] ?? true;

        // Resolve the recovering contractor's stock location (their van), per the fix.
        $location = $routeToContractor
            ? StockLocation::query()->where('operator_code', $swap->operator_code)
                ->where('type', 'CONTRACTOR_VAN')->where('contractor_id', $contractorId)->where('active', true)->first()
            : null;

        // Tech removes the old unit: IN_FIELD_ACTIVE -> RECOVERED_BY_CONTRACTOR.
        if ($source->state === EquipmentInstance::IN_FIELD_ACTIVE || $source->state === EquipmentInstance::IN_FIELD_DEFECTIVE) {
            $this->instances->transition($source, EquipmentInstance::RECOVERED_BY_CONTRACTOR, [
                'reference' => $swap->swap_id,
            ]);
        }
        $source->refresh();

        // Route the recovered unit into the recovering contractor's stock (the fix).
        $this->instances->transition($source, EquipmentInstance::IN_CONTRACTOR_STOCK, [
            'location_id' => $location?->location_id,
            'reference' => $swap->swap_id,
        ]);

        if ($location) {
            $this->stock->move([
                'operator_code' => $swap->operator_code,
                'sku_id' => $source->sku_id,
                'location_id' => $location->location_id,
                'quantity' => 1,
                'reason_code' => 'SWAP_RECOVERY',
                'reference' => $swap->swap_id,
            ]);
        }

        $swap->update(['status' => EquipmentSwapRequest::SOURCE_RECOVERED]);

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SOURCE_RECOVERED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'sourceInstanceId' => $source->instance_id, 'recoveryContractorId' => $contractorId, 'routedToLocation' => $location?->location_id],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['routedToLocation' => $location?->location_id]);
    }
}
