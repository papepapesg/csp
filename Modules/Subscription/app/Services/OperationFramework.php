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
use Modules\Subscription\Models\SubscriptionOperationConfig;
use App\Foundation\Workflow\WorkflowRuntime;
use Modules\Workflow\Models\ProcessInstance;

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
        private readonly WorkflowRuntime $engine,
    ) {}

    /** Convention fallback: operation kind -> process definition key. */
    public static function processKeyFor(string $kind): string
    {
        return 'sub-'.Str::slug(strtolower($kind));
    }

    /**
     * R-SUB-WF-FW-7: resolve the BPMN/process key from subscription_operation_config
     * for the (operator, kind); fall back to the naming convention. Rejects when the
     * kind is disabled for the operator.
     */
    private function resolveProcessKey(string $operator, string $kind): string
    {
        $config = SubscriptionOperationConfig::resolve($operator, $kind);
        if ($config && ! $config->enabled) {
            throw DomainException::conflict("Operation {$kind} is disabled for operator {$operator}.");
        }

        return $config?->default_bpmn_process_key ?? self::processKeyFor($kind);
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

        // Concurrency (R-SUB-WF-FW-1): at most one in-flight state-changing
        // (non-RESTRICT) operation per subscription. RESTRICT is non-exclusive
        // (R-SUB-WF-FW-2). In-flight == final_state IS NULL (matches the partial
        // unique indexes that also enforce this at the DB level).
        $inflight = $exclusive && SubscriptionOperation::query()
            ->where('subscription_id', $subscription->subscription_id)
            ->where('operation_kind', '!=', 'RESTRICT')
            ->whereNull('final_state')
            ->exists();
        if ($inflight) {
            throw DomainException::conflict(
                'Another operation is already in progress for this subscription.',
                nextAction: 'WAIT_FOR_OPERATION',
            );
        }

        $processKey = $this->resolveProcessKey($subscription->operator_code, $kind);

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
                'current_state' => SubscriptionOperation::INITIATED,
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
            'current_state' => SubscriptionOperation::VALIDATING,
            'started_at' => now(),
        ]);

        return $operation->refresh();
    }

    /**
     * SUB-WF-FRAMEWORK-01 §8.2 cancel an in-flight operation. Marks the operation
     * CANCELLED, cancels its workflow instance, and reverts the subscription master
     * to its prior status if a transient PENDING_* flip had been applied
     * (R-SUB-WF-FW-3 compensation).
     */
    public function cancel(SubscriptionOperation $operation, string $reason, ?string $actorId = null): SubscriptionOperation
    {
        if (! $operation->isInFlight()) {
            throw DomainException::conflict('Operation is already terminal.', nextAction: 'NONE');
        }

        return DB::transaction(function () use ($operation, $reason, $actorId) {
            // Cancel the workflow instance if still running.
            if ($operation->bpmn_process_instance_id) {
                ProcessInstance::query()->whereKey($operation->bpmn_process_instance_id)
                    ->where('status', ProcessInstance::RUNNING)
                    ->update(['status' => ProcessInstance::CANCELLED, 'ended_at' => now()]);
            }

            // Compensation: if the master is sitting in a transient PENDING_* state,
            // revert it to the prior status.
            $subscription = Subscription::query()->find($operation->subscription_id);
            if ($subscription && str_starts_with((string) $subscription->status_code, 'PENDING_')
                && $operation->prior_subscription_status) {
                $subscription->update(['status_code' => $operation->prior_subscription_status, 'last_status_changed_at' => now()]);
            }

            $operation->markCancelled($reason, $actorId);

            $this->events->publish(new DomainEvent(
                type: SubscriptionEvents::OPERATION_CANCELLED,
                topic: SubscriptionEvents::TOPIC,
                payload: ['operationId' => $operation->operation_id, 'reason' => $reason, 'revertedTo' => $subscription?->status_code],
                aggregateType: 'SubscriptionOperation',
                aggregateId: $operation->operation_id,
            ));

            return $operation->refresh();
        });
    }
}
