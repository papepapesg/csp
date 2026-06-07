<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Events\SubscriptionEvents;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/** SUB-WF-RESUME-01 toolbox step: move a PAUSED subscription back to ACTIVE. */
class ResumeHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.resume';
    }

    public function label(): string
    {
        return 'Subscription: Resume';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }
        if ($subscription->status_code === Subscription::PAUSED) {
            $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, [
                'resumed_at' => now(),
            ], SubscriptionEvents::RESUMED);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::ACTIVE]);
    }
}
