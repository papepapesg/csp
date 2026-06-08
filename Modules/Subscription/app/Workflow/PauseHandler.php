<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-PAUSE-01 commit (sub-lm-commit-state). R-PAUSE-S-2: the master commits to
 * SUSPENDED (with a pause reason) — pause is a SUSPENDED rest state, not a separate
 * status. Runs after the PENDING_PAUSE flip; narrates COMMITTING_FINAL_STATE.
 */
class PauseHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.pause';
    }

    public function label(): string
    {
        return 'Subscription: Commit pause (SUSPENDED)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::COMMITTING_FINAL_STATE);

        if ($subscription->status_code !== Subscription::SUSPENDED && ! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::SUSPENDED, [
                'current_transition_reason_code' => $context->var('reasonCode', 'CUSTOMER_REQUESTED_PAUSE'),
            ], SubscriptionEvents::PAUSED);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::SUSPENDED]);
    }
}
