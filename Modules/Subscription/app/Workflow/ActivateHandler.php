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
            // R-BIL-03-V-2: open the first billing cycle at activation so the
            // cycle-close scanner has a boundary to evaluate (current_cycle_end =
            // start + one period). Only when not already running a cycle.
            $extra = ['start_date' => $subscription->start_date ?? now()->toDateString()];
            if (! $subscription->current_cycle_end) {
                $start = now();
                $extra['current_cycle_start'] = $start;
                // CALENDAR cycles align to an anchor day: the first cycle runs
                // from activation to the next anchor (a PARTIAL cycle, prorated at
                // close). ANNIVERSARY/default cycles run a full period from start.
                if (($subscription->cycle_model ?? 'CALENDAR') === 'CALENDAR' && $subscription->cycle_anchor_day) {
                    $extra['current_cycle_end'] = $this->nextAnchor($start, (int) $subscription->cycle_anchor_day);
                } else {
                    $extra['current_cycle_end'] = (clone $start)->add($subscription->cyclePeriod());
                }
            }
            $this->subscriptions->transitionStatus($subscription, Subscription::ACTIVE, $extra);
        }

        return TaskResult::success(['subscriptionStatus' => Subscription::ACTIVE]);
    }

    /** The next occurrence of the calendar anchor day strictly after $from. */
    private function nextAnchor(\Carbon\Carbon $from, int $anchorDay): \Carbon\Carbon
    {
        $candidate = $from->copy()->day(min($anchorDay, $from->daysInMonth));
        if ($candidate->lessThanOrEqualTo($from)) {
            $next = $from->copy()->addMonthNoOverflow()->startOfMonth();
            $candidate = $next->day(min($anchorDay, $next->daysInMonth));
        }

        return $candidate;
    }
}
