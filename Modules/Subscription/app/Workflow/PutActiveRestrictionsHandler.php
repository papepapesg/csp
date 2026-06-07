<?php

namespace Modules\Subscription\Workflow;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\RestrictionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-RESTRICT-01 toolbox step (sub-lm-put-active-restrictions): mutate the
 * subscription's active_restrictions[] JSONB array for the carried intent
 * (ADD/REMOVE). status_code is intentionally untouched (R-S-2). The row is locked
 * for the mutation so concurrent RESTRICT runs serialize (R-S-3). Emits the rich
 * SubscriptionRestrictionAdded / Removed domain event after the commit.
 *
 * Network-side enforcement of the fulfillment_action is owned by FUL-04; the
 * action code travels on the emitted event for that consumer.
 */
class PutActiveRestrictionsHandler implements TaskHandler
{
    public function __construct(private readonly EventBus $events) {}

    public function topic(): string
    {
        return 'sub.put-active-restrictions';
    }

    public function label(): string
    {
        return 'Subscription: Put active restrictions';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $intent = $context->var('intent', 'ADD');
        $code = $context->var('restrictionCode');
        $actorUserId = $context->var('actorUserId') ?? 'system';

        $result = DB::transaction(function () use ($context, $intent, $code, $actorUserId) {
            /** @var Subscription|null $subscription */
            $subscription = Subscription::query()->lockForUpdate()->find($context->businessKey());
            if (! $subscription) {
                return null;
            }

            $restrictions = $subscription->active_restrictions ?? [];
            $previous = array_values(array_map(fn ($e) => $e['restrictionCode'], $restrictions));

            if ($intent === 'REMOVE') {
                $restrictions = array_values(array_filter($restrictions, fn ($e) => ($e['restrictionCode'] ?? null) !== $code));
            } else {
                $restrictions[] = RestrictionService::buildEntry($context->variables(), $actorUserId);
            }

            $subscription->update(['active_restrictions' => $restrictions]);
            $newCodes = array_values(array_map(fn ($e) => $e['restrictionCode'], $restrictions));

            return [$subscription, $previous, $newCodes];
        });

        if ($result === null) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        [$subscription, $previous, $newCodes] = $result;

        $this->events->publish(new DomainEvent(
            type: $intent === 'REMOVE' ? SubscriptionEvents::RESTRICTION_REMOVED : SubscriptionEvents::RESTRICTION_ADDED,
            topic: SubscriptionEvents::TOPIC,
            payload: [
                'subscriptionId' => $subscription->subscription_id,
                'customerId' => $subscription->customer_id,
                'operationId' => $context->var('operationId'),
                'restrictionCode' => $code,
                'fulfillmentAction' => $context->var('fulfillmentAction'),
                'activationTrigger' => $context->var('activationTrigger'),
                'dunningMarker' => (bool) $context->var('dunningMarker', false),
                'dunningReference' => $context->var('dunningReference'),
                'dunningOverride' => (bool) $context->var('dunningOverride', false),
                'actorUserId' => $actorUserId,
                'previousActiveRestrictions' => $previous,
                'newActiveRestrictions' => $newCodes,
            ],
            aggregateType: 'Subscription',
            aggregateId: $subscription->subscription_id,
        ));

        return TaskResult::success([
            'restrictionCode' => $code,
            'intent' => $intent,
            'recipient' => $subscription->customer_id,
        ]);
    }
}
