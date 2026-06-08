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
 * SUB-WF-RESUME-01 commit. R-RESUME-S-2: SUSPENDED -> PENDING_RESUME -> ACTIVE.
 * Runs after the PENDING_RESUME flip + FUL-03 reactivation; narrates the commit.
 */
class ResumeHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.resume';
    }

    public function label(): string
    {
        return 'Subscription: Commit resume (ACTIVE)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::COMMITTING_FINAL_STATE);

        if ($subscription->status_code !== Subscription::ACTIVE && ! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, [
                'resumed_at' => now(),
                'current_transition_reason_code' => $context->var('reasonCode', 'CUSTOMER_REQUESTED_RESUME'),
            ], SubscriptionEvents::RESUMED);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::ACTIVE]);
    }
}
