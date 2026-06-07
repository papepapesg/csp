<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * Toolbox step: validate that a subscription may be activated. Side-effect free;
 * returns `eligible` for a downstream gateway to branch on.
 */
class ValidateActivationHandler implements TaskHandler
{
    public function topic(): string
    {
        return 'sub.validate-activation';
    }

    public function label(): string
    {
        return 'Subscription: Validate activation';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        $eligible = ! $subscription->isTerminal();

        return TaskResult::success([
            'eligible' => $eligible,
            'recipient' => $context->var('recipient'),
        ]);
    }
}
