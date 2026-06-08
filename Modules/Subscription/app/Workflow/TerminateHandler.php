<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-TERMINATE-01 commit. ACTIVE -> PENDING_TERMINATION -> TERMINATED. Runs
 * after the PENDING_TERMINATION flip + equipment-pickup/fulfillment; narrates the
 * commit.
 */
class TerminateHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.terminate';
    }

    public function label(): string
    {
        return 'Subscription: Commit termination (TERMINATED)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::COMMITTING_FINAL_STATE);

        if (! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::TERMINATED, [
                'current_transition_reason_code' => $context->var('reasonCode', 'CUSTOMER_REQUESTED_TERMINATION'),
                'end_date' => now()->toDateString(),
            ]);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::TERMINATED]);
    }
}
