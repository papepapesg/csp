<?php

namespace Modules\Subscription\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Workflow\Engine\WorkflowEngine;

/**
 * SUB-WF-FRAMEWORK — starts and tracks subscription operations.
 *
 * Enforces idempotency (operator + key) and single-in-flight concurrency, then
 * hands orchestration to the config-driven workflow engine. The flow shape lives
 * in a process_definition (data, authored in the studio), NOT in code — so a new
 * operator/market is a new definition row. Operation kind maps to a process key
 * by convention (ACTIVATE -> sub-activate); the operation ledger is reconciled
 * from the engine's ProcessInstanceEnded event.
 */
class OperationFramework
{
    public function __construct(
        private readonly EventBus $events,
        private readonly WorkflowEngine $engine,
    ) {}

    /** Convention: operation kind -> process definition key. */
    public static function processKeyFor(string $kind): string
    {
        return 'sub-'.Str::slug(strtolower($kind));
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public function trigger(
        Subscription $subscription,
        string $kind,
        array $input = [],
        ?string $idempotencyKey = null,
        ?string $requestHash = null,
        ?string $actorUserId = null,
        ?string $actorRole = null,
        bool $exclusive = true,
    ): SubscriptionOperation {
        $idempotencyKey ??= Id::make('idem');

        // Idempotent replay: same operator + key returns the original operation.
        $existing = SubscriptionOperation::query()
            ->where('operator_code', $subscription->operator_code)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Concurrency: refuse a second in-flight EXCLUSIVE operation on the same
        // subscription. RESTRICT is non-exclusive (R-FW-1 / R-SUB-WF-RESTRICT-01-S-3):
        // it does not mutate status_code so it may run alongside other operations.
        $inflight = $exclusive && SubscriptionOperation::query()
            ->where('subscription_id', $subscription->subscription_id)
            ->where('operation_kind', '!=', 'RESTRICT')
            ->whereIn('current_state', [SubscriptionOperation::PENDING, SubscriptionOperation::RUNNING])
            ->exists();
        if ($inflight) {
            throw DomainException::conflict(
                'Another operation is already in progress for this subscription.',
                nextAction: 'WAIT_FOR_OPERATION',
            );
        }

        $processKey = self::processKeyFor($kind);

        $operation = DB::transaction(function () use ($subscription, $kind, $input, $idempotencyKey, $requestHash, $actorUserId, $actorRole, $processKey) {
            $operation = SubscriptionOperation::query()->create([
                'operation_id' => Id::operation(),
                'operator_code' => $subscription->operator_code,
                'subscription_id' => $subscription->subscription_id,
                'operation_kind' => $kind,
                'bpmn_process_key' => $processKey,
                'initiating_actor_user_id' => $actorUserId,
                'initiating_actor_role' => $actorRole,
                'idempotency_key' => $idempotencyKey,
                'idempotency_request_hash' => $requestHash ?? hash('sha256', json_encode($input)),
                'correlation_id' => Context::correlationId(),
                'prior_subscription_status' => $subscription->status_code,
                'current_state' => SubscriptionOperation::PENDING,
                'input' => $input,
            ]);

            $this->events->publish(new DomainEvent(
                type: SubscriptionEvents::OPERATION_STARTED,
                topic: SubscriptionEvents::TOPIC,
                payload: ['operationId' => $operation->operation_id, 'kind' => $kind, 'subscriptionId' => $subscription->subscription_id],
                aggregateType: 'SubscriptionOperation',
                aggregateId: $operation->operation_id,
            ));

            return $operation;
        });

        // Start the data-defined workflow; variables carry IDs + control flags only.
        $instance = $this->engine->start(
            processKey: $processKey,
            businessKey: $subscription->subscription_id,
            variables: array_merge($input, [
                'subscriptionId' => $subscription->subscription_id,
                'operationId' => $operation->operation_id,
                'operationKind' => $kind,
                'customerId' => $subscription->customer_id,
            ]),
            operator: $subscription->operator_code,
        );

        $operation->update([
            'bpmn_process_instance_id' => $instance->instance_id,
            'current_state' => SubscriptionOperation::RUNNING,
            'started_at' => now(),
        ]);

        return $operation->refresh();
    }
}
