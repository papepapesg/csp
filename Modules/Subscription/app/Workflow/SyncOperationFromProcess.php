<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Engine\ProcessInstanceEnded;

/**
 * Reconciles the SUB-WF operation ledger when its workflow instance ends. SUB-WF
 * owns the operation row; it reacts to the engine rather than the engine knowing
 * about subscription tables (HLD §6.2 separation).
 */
class SyncOperationFromProcess
{
    public function __construct(private readonly EventBus $events) {}

    public function handle(ProcessInstanceEnded $event): void
    {
        $operationId = $event->instance->variables['operationId'] ?? null;
        if (! $operationId) {
            return;
        }

        $operation = SubscriptionOperation::query()->find($operationId);
        // Skip if already terminal (COMPLETED/FAILED/CANCELLED) — final_state is set.
        if (! $operation || ! $operation->isInFlight()) {
            return;
        }

        $completed = $event->instance->status === 'COMPLETED';
        $finalSubStatus = optional(Subscription::query()->find($operation->subscription_id))->status_code;

        if ($completed) {
            $operation->markCompleted($finalSubStatus ?? 'UNKNOWN');
        } else {
            $operation->markFailed('WORKFLOW_FAILED', (string) $event->instance->error_message);
        }

        $this->events->publish(new DomainEvent(
            type: $completed ? SubscriptionEvents::OPERATION_COMPLETED : SubscriptionEvents::OPERATION_FAILED,
            topic: SubscriptionEvents::TOPIC,
            payload: ['operationId' => $operation->operation_id, 'finalState' => $operation->final_state],
            aggregateType: 'SubscriptionOperation',
            aggregateId: $operation->operation_id,
        ));
    }
}
