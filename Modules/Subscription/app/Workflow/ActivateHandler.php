<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: drive a subscription to ACTIVE (SUB-WF-ACTIVATE-01). Idempotent.
 */
class ActivateHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.activate';
    }

    public function label(): string
    {
        return 'Subscription: Set active';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        if ($subscription->status_code !== Subscription::ACTIVE && ! $subscription->isTerminal()) {
            $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, [
                'start_date' => $subscription->start_date ?? now()->toDateString(),
            ]);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::ACTIVE]);
    }
}
