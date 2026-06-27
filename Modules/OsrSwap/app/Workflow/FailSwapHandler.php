<?php

namespace Modules\Osr\Swap\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Swap\Models\EquipmentSwapRequest;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** OSR-RMA-01 eligibility failure: no slot reserved, no WO, no truck roll. */
class FailSwapHandler implements TaskHandler
{
    public function __construct(private readonly EventBus $events) {}

    public function topic(): string
    {
        return 'osr.fail-swap';
    }

    public function label(): string
    {
        return 'OSR-RMA: Fail swap (ineligible)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $swap = EquipmentSwapRequest::query()->find($context->businessKey());
        if (! $swap) {
            return TaskResult::fail('Swap request not found', retryable: false);
        }

        $swap->update(['status' => EquipmentSwapRequest::FAILED, 'failure_code' => $context->var('eligibilityReason', 'INELIGIBLE')]);

        $this->events->publish(new DomainEvent(
            type: OsrEvents::SWAP_REJECTED,
            topic: OsrEvents::TOPIC,
            payload: ['swapId' => $swap->swap_id, 'failureCode' => $swap->failure_code],
            aggregateType: 'EquipmentSwapRequest',
            aggregateId: $swap->swap_id,
        ));

        return TaskResult::success(['swapStatus' => EquipmentSwapRequest::FAILED]);
    }
}
