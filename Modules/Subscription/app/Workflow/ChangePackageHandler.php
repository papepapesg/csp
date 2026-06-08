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
 * SUB-WF-UPGRADE-01 / DOWNGRADE-01 commit step (sub-lm-put-master-fields +
 * sub-lm-commit-state). Pins the source package to previous_package_ref for audit,
 * sets the new package_ref/version, records the transition type, and emits the
 * domain event. status_code stays ACTIVE (the PENDING_* transient is internal to
 * the saga). IMMEDIATE timing commits here; network reconfig is FUL-03's job.
 *   config: { transition: 'UPGRADE', event: 'SubscriptionUpgraded' }
 */
class ChangePackageHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.change-package';
    }

    public function label(): string
    {
        return 'Subscription: Change package (upgrade/downgrade)';
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        SubscriptionOperation::narrate(
            $context->var('operationId'), SubscriptionOperation::COMMITTING_FINAL_STATE
        );

        $cfg = $context->config();
        $transition = $cfg['transition'] ?? 'UPGRADE';
        $event = $cfg['event'] ?? SubscriptionEvents::UPGRADED;
        $targetPackageRef = $context->var('targetPackageRef');

        // Commit to ACTIVE with the new package; the transient PENDING_UPGRADE flip
        // + current_transition_type were set by the enter-pending step and are
        // cleared here (rest state).
        $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, [
            'previous_package_ref' => $subscription->package_ref,
            'previous_package_version_id' => $subscription->package_version_id,
            'package_ref' => $targetPackageRef,
            'package_version_id' => $context->var('targetPackageVersionId'),
        ], $event);

        return TaskResult::success([
            'packageRef' => $targetPackageRef,
            'transition' => $transition,
            'recipient' => $subscription->customer_id,
        ]);
    }
}
