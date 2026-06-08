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
 * SUB-WF-RELOCATION-01 / MIGRATION-01 commit step. Pins the source HomePass to
 * previous_homepass_id, moves the subscription to the target HomePass, and (for
 * migration) changes the package too. status_code stays ACTIVE (the PENDING_*
 * transient is internal to the commit window). Network teardown/activation across
 * the two HomePasses is FUL-03/04's job; physical work is the WO-01 SHIFTING flow.
 *   config: { transition: 'RELOCATION', event: 'SubscriptionRelocated' }
 */
class ChangeHomePassHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.change-homepass';
    }

    public function label(): string
    {
        return 'Subscription: Change HomePass (relocation/migration)';
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
        $transition = $cfg['transition'] ?? 'RELOCATION';
        $event = $cfg['event'] ?? SubscriptionEvents::RELOCATED;

        // Commit to ACTIVE on the new HomePass; PENDING_* flip set upstream, cleared here.
        $attrs = [
            'previous_homepass_id' => $subscription->homepass_id,
            'homepass_id' => $context->var('targetHomepassId'),
        ];

        // Migration also changes the package.
        if ($targetPackageRef = $context->var('targetPackageRef')) {
            $attrs['previous_package_ref'] = $subscription->package_ref;
            $attrs['previous_package_version_id'] = $subscription->package_version_id;
            $attrs['package_ref'] = $targetPackageRef;
            $attrs['package_version_id'] = $context->var('targetPackageVersionId');
        }

        $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, $attrs, $event);

        return TaskResult::success([
            'homepassId' => $context->var('targetHomepassId'),
            'transition' => $transition,
            'recipient' => $subscription->customer_id,
        ]);
    }
}
