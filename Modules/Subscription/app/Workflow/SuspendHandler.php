<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** SUB-WF-SUSPEND-NP-01 toolbox step: suspend an ACTIVE subscription. */
class SuspendHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.suspend';
    }

    public function label(): string
    {
        return 'Subscription: Suspend';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        if (in_array($subscription->status_code, [Subscription::ACTIVE, Subscription::RESTRICTED], true)) {
            $this->subscriptions->transitionStatus($subscription, Subscription::SUSPENDED, [
                'current_transition_reason_code' => $context->var('reasonCode', 'NON_PAYMENT'),
            ], SubscriptionEvents::SUSPENDED);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::SUSPENDED]);
    }
}
