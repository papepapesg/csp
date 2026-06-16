<?php

namespace Modules\Subscription\Workflow;

use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\SubscriptionService;
use Modules\Workflow\Contracts\Io;
use Modules\Workflow\Contracts\TaskContext;
use Modules\Workflow\Contracts\TaskHandler;
use Modules\Workflow\Contracts\TaskResult;

/**
 * SUB-WF-FRAMEWORK sub-lm-put-pending-status step. Moves the subscription master
 * into the operation's transient PENDING_* state during the commit window
 * (e.g. ACTIVE -> PENDING_PAUSE) and stamps current_transition_type +
 * current_transition_reason_code. Narrates the operation as PENDING_STATE_FLIP.
 *   config: { pendingStatus: 'PENDING_PAUSE', transitionType: 'PAUSE' }
 */
class EnterPendingStatusHandler implements TaskHandler
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function topic(): string
    {
        return 'sub.put-pending-status';
    }

    public function label(): string
    {
        return 'Subscription: Enter pending status';
    }

    /** @return array<int,array<string,mixed>> */
    public function inputs(): array
    {
        return [
            Io::in('pendingStatus', Io::STRING, 'Transient PENDING_* status to hold during the commit window (e.g. PENDING_PAUSE).', true),
            Io::in('transitionType', Io::STRING, 'Operation transition type stamped on the subscription (defaults to the operation kind).'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function outputs(): array
    {
        return [Io::out('pendingStatus', Io::STRING, 'The pending status that was applied.')];
    }

    public function handle(TaskContext $context): TaskResult
    {
        $subscription = Subscription::query()->find($context->businessKey());
        if (! $subscription) {
            return TaskResult::fail('Subscription not found', retryable: false);
        }

        SubscriptionOperation::narrate($context->var('operationId'), SubscriptionOperation::PENDING_STATE_FLIP);

        $cfg = $context->config();
        $pending = $cfg['pendingStatus'] ?? null;
        $transition = $cfg['transitionType'] ?? $context->var('operationKind');

        if ($pending && ! $subscription->isTerminal()) {
            $this->subscriptions->setPendingStatus($subscription, $pending, $transition, $context->var('reasonCode'));
        }

        return TaskResult::success(['pendingStatus' => $pending]);
    }
}
