<?php

namespace Modules\Subscription\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Jobs\RunSubscriptionOperation;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Workflows\ActivateSubscriptionWorkflow;
use Modules\Subscription\Workflows\TerminateSubscriptionWorkflow;

/**
 * SUB-WF-FRAMEWORK — starts and tracks subscription operations.
 *
 * Enforces idempotency (operator + key), single-in-flight concurrency, and the
 * "return an operation id, track asynchronously" contract (DD_API-00 §7). The
 * registry maps each operation kind to its native workflow.
 */
class OperationFramework
{
    /** Operation kind -> workflow class (SUB-WF flow satellites). */
    public const REGISTRY = [
        'ACTIVATE' => ActivateSubscriptionWorkflow::class,
        'TERMINATE' => TerminateSubscriptionWorkflow::class,
    ];

    public function __construct(private readonly EventBus $events) {}

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
    ): SubscriptionOperation {
        if (! isset(self::REGISTRY[$kind])) {
            throw DomainException::ruleRejected('UNKNOWN_OPERATION', "Unknown operation kind [{$kind}].");
        }

        $idempotencyKey ??= Id::make('idem');

        // Idempotent replay: same operator + key returns the original operation.
        $existing = SubscriptionOperation::query()
            ->where('operator_code', $subscription->operator_code)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Concurrency: refuse a second in-flight operation on the same subscription.
        $inflight = SubscriptionOperation::query()
            ->where('subscription_id', $subscription->subscription_id)
            ->whereIn('current_state', [SubscriptionOperation::PENDING, SubscriptionOperation::RUNNING])
            ->exists();
        if ($inflight) {
            throw DomainException::conflict(
                'Another operation is already in progress for this subscription.',
                nextAction: 'WAIT_FOR_OPERATION',
            );
        }

        $operation = DB::transaction(function () use ($subscription, $kind, $input, $idempotencyKey, $requestHash, $actorUserId, $actorRole) {
            $operation = SubscriptionOperation::query()->create([
                'operation_id' => Id::operation(),
                'operator_code' => $subscription->operator_code,
                'subscription_id' => $subscription->subscription_id,
                'operation_kind' => $kind,
                'bpmn_process_key' => self::REGISTRY[$kind],
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

        RunSubscriptionOperation::dispatch($operation->operation_id);

        return $operation;
    }
}
