<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** SUB-WF-PAUSE-01 toolbox step: move an ACTIVE subscription to PAUSED. */
class PauseHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.pause';
    }

    public function label(): string
    {
        return 'Subscription: Pause';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        if ($subscription->status_code === Subscription::ACTIVE) {
            $this->subscriptions->transitionStatus($subscription, Subscription::PAUSED, [
                'current_transition_reason_code' => $context->var('reasonCode'),
            ], SubscriptionEvents::PAUSED);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::PAUSED]);
    }
}
