<?php

namespace Modules\Billing\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\DunningState;
use Modules\Billing\Models\Invoice;
use Modules\Notification\Services\NotificationService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\OperationFramework;

/**
 * BIL-04 Dunning Engine. Detects unpaid debt past grace and drives the
 * configurable escalation policy (warning -> restrict -> suspend -> terminate)
 * by advancing a per-account level and calling the owning SUB-WF operations.
 * The policy itself lives in the rules.billing.dunning decision table, so grace
 * periods and actions per level are configuration (BIL-04 §escalation policy).
 */
class DunningService
{
    /** Level -> the SUB-WF operation kind to trigger when entering it. */
    private const LEVEL_ACTION = [
        DunningState::LEVEL_WARNING => 'WARN',
        DunningState::LEVEL_RESTRICTED => 'RESTRICT',
        DunningState::LEVEL_SUSPENDED => 'SUSPEND',
        DunningState::LEVEL_TERMINATED => 'TERMINATE',
    ];

    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly OperationFramework $operations,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Scan accounts with overdue debt and advance dunning where grace elapsed.
     *
     * @return array{scanned:int, advanced:int}
     */
    public function scan(): array
    {
        // Accounts with overdue, still-open invoices.
        $accounts = Invoice::query()
            ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
            ->where('amount_due', '>', 0)
            ->where('due_date', '<', now())
            ->select('account_id', 'operator_code')
            ->selectRaw('SUM(amount_due) as debt')
            ->selectRaw('MIN(due_date) as oldest_due')
            ->groupBy('account_id', 'operator_code')
            ->get();

        $advanced = 0;
        foreach ($accounts as $row) {
            if ($this->assessAccount($row->account_id, $row->operator_code, (float) $row->debt, $row->oldest_due)) {
                $advanced++;
            }
        }

        return ['scanned' => $accounts->count(), 'advanced' => $advanced];
    }

    private function assessAccount(string $accountId, string $operator, float $debt, string $oldestDue): bool
    {
        Context::setOperatorCode($operator);

        $state = DunningState::query()->firstOrNew(['operator_code' => $operator, 'account_id' => $accountId]);
        if (! $state->exists) {
            $state->current_level = DunningState::LEVEL_NONE;
            $state->entered_level_at = now();
            $state->status = 'ACTIVE';
        }

        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $state->subscription_id = $subscription?->subscription_id;
        $state->outstanding_debt_amount = $debt;
        $state->last_scanned_at = now();

        // Carbon 3 diffs are signed; dunning needs elapsed-day counts.
        $daysOverdue = (int) abs(now()->diffInDays($oldestDue));
        $daysAtLevel = $state->entered_level_at ? (int) abs(now()->diffInDays($state->entered_level_at)) : 0;

        // Configurable escalation decision (rules.billing.dunning).
        $decision = $this->rules->evaluate('rules.billing.dunning', [
            'currentLevel' => $state->current_level,
            'daysOverdue' => $daysOverdue,
            'daysAtLevel' => $daysAtLevel,
            'outstandingBalance' => $debt,
        ]);
        $nextLevel = (int) ($decision['nextLevel'] ?? $state->current_level);

        if ($nextLevel <= $state->current_level) {
            $state->save();

            return false;
        }

        return DB::transaction(function () use ($state, $nextLevel, $subscription, $decision) {
            $state->current_level = $nextLevel;
            $state->entered_level_at = now();
            $state->save();

            $this->events->publish(new DomainEvent(
                type: BillingEvents::DUNNING_STAGE_ADVANCED,
                topic: BillingEvents::TOPIC,
                payload: ['accountId' => $state->account_id, 'level' => $nextLevel, 'decisionCode' => $decision['action'] ?? null, 'debt' => (string) $state->outstanding_debt_amount],
                aggregateType: 'DunningState',
                aggregateId: $state->dunning_id,
            ));

            $this->applyAction($nextLevel, $subscription, $state);

            return true;
        });
    }

    private function applyAction(int $level, ?Subscription $subscription, DunningState $state): void
    {
        $action = self::LEVEL_ACTION[$level] ?? 'NONE';

        if ($action === 'WARN' || ! $subscription) {
            if ($subscription) {
                $this->notifications->send([
                    'channel' => 'SMS', 'recipient' => $subscription->customer_id, 'template_code' => 'DUNNING_WARNING',
                    'reference' => $state->account_id, 'body' => 'Your account has an overdue balance.',
                ]);
            }

            return;
        }

        // Drive the owning SUB-WF operation (idempotent by dunning level).
        if (in_array($action, ['SUSPEND', 'TERMINATE'], true)) {
            $this->operations->trigger(
                subscription: $subscription,
                kind: $action,
                input: ['reasonCode' => 'NON_PAYMENT', 'dunningLevel' => $level],
                idempotencyKey: "dunning-{$state->account_id}-L{$level}",
            );

            if ($action === 'SUSPEND') {
                $this->events->publish(new DomainEvent(
                    type: BillingEvents::SUBSCRIPTION_SUSPENDED_NP,
                    topic: BillingEvents::TOPIC,
                    payload: ['subscriptionId' => $subscription->subscription_id, 'accountId' => $state->account_id],
                    aggregateType: 'Subscription',
                    aggregateId: $subscription->subscription_id,
                ));
            }
        }
    }

    /** Clear dunning for an account once debt is settled (BIL-04 de-escalation). */
    public function clear(string $accountId): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', 'ACTIVE')->first();
        if (! $state) {
            return;
        }
        $state->update(['current_level' => DunningState::LEVEL_NONE, 'status' => 'CLEARED', 'outstanding_debt_amount' => 0, 'entered_level_at' => now()]);
        $this->events->publish(new DomainEvent(
            type: BillingEvents::DUNNING_CLEARED,
            topic: BillingEvents::TOPIC,
            payload: ['accountId' => $accountId],
            aggregateType: 'DunningState',
            aggregateId: $state->dunning_id,
        ));
    }
}
