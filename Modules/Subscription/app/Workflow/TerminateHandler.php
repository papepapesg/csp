<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: drive a subscription to TERMINATED (SUB-WF-TERMINATE-01).
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
        return 'Subscription: Terminate';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        if (! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::TERMINATED, [
                'current_transition_reason_code' => $context->var('reasonCode'),
                'end_date' => now()->toDateString(),
            ]);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::TERMINATED]);
    }
}
