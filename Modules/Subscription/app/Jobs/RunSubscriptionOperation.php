<?php

namespace Modules\Subscription\Jobs;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\OperationFramework;
use Modules\Subscription\Workflows\SubscriptionWorkflow;

/**
 * Queued worker executing one SUB-WF operation (native Camunda driver). The
 * workflow drives SUB-LM status; this job records ledger state + outcome events.
 */
class RunSubscriptionOperation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $operationId) {}

    public function handle(EventBus $events): void
    {
        $operation = SubscriptionOperation::query()->find($this->operationId);
        if (! $operation || in_array($operation->current_state, [SubscriptionOperation::COMPLETED, SubscriptionOperation::CANCELLED], true)) {
            return;
        }

        if ($operation->correlation_id) {
            Context::setCorrelationId($operation->correlation_id);
        }
        Context::setOperatorCode($operation->operator_code);

        $workflowClass = OperationFramework::REGISTRY[$operation->operation_kind] ?? null;

        try {
            if (! $workflowClass) {
                throw DomainException::ruleRejected('UNKNOWN_OPERATION', "No workflow for {$operation->operation_kind}.");
            }

            /** @var SubscriptionWorkflow $workflow */
            $workflow = app($workflowClass);
            $operation->markRunning();
            $finalState = $workflow->handle($operation);
            $operation->markCompleted($finalState);

            $events->publish(new DomainEvent(
                type: SubscriptionEvents::OPERATION_COMPLETED,
                topic: SubscriptionEvents::TOPIC,
                payload: ['operationId' => $operation->operation_id, 'finalState' => $finalState],
                aggregateType: 'SubscriptionOperation',
                aggregateId: $operation->operation_id,
            ));
        } catch (DomainException $e) {
            $this->fail($operation, $events, $e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $this->fail($operation, $events, 'INTERNAL_ERROR', $e->getMessage());
            throw $e; // allow retry
        }
    }

    private function fail(SubscriptionOperation $operation, EventBus $events, string $code, string $detail): void
    {
        $operation->markFailed($code, $detail);
        $events->publish(new DomainEvent(
            type: SubscriptionEvents::OPERATION_FAILED,
            topic: SubscriptionEvents::TOPIC,
            payload: ['operationId' => $operation->operation_id, 'reasonCode' => $code],
            aggregateType: 'SubscriptionOperation',
            aggregateId: $operation->operation_id,
        ));
    }
}
