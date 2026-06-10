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
use Modules\Subscription\Services\RestrictionService;

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
        DunningState::LEVEL_SUSPENDED => 'SUSPEND_NP',
        DunningState::LEVEL_TERMINATED => 'TERMINATE',
    ];

    public function __construct(
        private readonly EventBus $events,
        private readonly RuleEngine $rules,
        private readonly OperationFramework $operations,
        private readonly NotificationService $notifications,
        private readonly RestrictionService $restrictions,
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
        $isNew = ! $state->exists;
        if ($isNew) {
            $state->current_level = DunningState::LEVEL_NONE;
            $state->entered_level_at = now();
            $state->status = DunningState::STATUS_ACTIVE;
        }

        // T-5/T-6: a paused or in-review dunning episode is not advanced by the scanner.
        if (in_array($state->status, [DunningState::STATUS_SUSPENDED_BY_PAUSE, DunningState::STATUS_PENDING_TERMINATION_REVIEW, DunningState::STATUS_RECOVERY_FAILED], true)) {
            return false;
        }
        // E-1/E-2: at-most-daily cadence — skip if not yet due for evaluation.
        if ($state->exists && $state->next_evaluation_at && $state->next_evaluation_at->isFuture()) {
            return false;
        }

        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $state->subscription_id = $subscription?->subscription_id;
        $state->billing_mode = $subscription?->billing_mode ?? 'POSTPAID';

        // T-2: debt grew at the same level → DebtIncreased, no level change.
        if (! $isNew && $debt > (float) $state->outstanding_debt_amount && $state->current_level > 0) {
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_DEBT_INCREASED, $state, ['debt' => (string) $debt]));
        }
        $state->outstanding_debt_amount = $debt;
        $state->last_scanned_at = now();
        $state->next_evaluation_at = now()->addDay(); // E-2

        $daysOverdue = (int) abs(now()->diffInDays($oldestDue));
        $daysAtLevel = $state->entered_level_at ? (int) abs(now()->diffInDays($state->entered_level_at)) : 0;

        $decision = $this->rules->evaluate('rules.billing.dunning', [
            'currentLevel' => $state->current_level,
            'daysOverdue' => $daysOverdue,
            'daysAtLevel' => $daysAtLevel,
            'outstandingBalance' => $debt,
        ]);
        // D-2 monotonic: advance at most one level per evaluation, never skip.
        $proposed = (int) ($decision['nextLevel'] ?? $state->current_level);
        $nextLevel = min($proposed, $state->current_level + 1);

        if ($nextLevel <= $state->current_level) {
            $state->save();

            return false;
        }

        return DB::transaction(function () use ($state, $nextLevel, $subscription, $decision, $operator) {
            $wasNone = $state->current_level === DunningState::LEVEL_NONE;
            $state->current_level = $nextLevel;
            $state->entered_level_at = now();

            // T-4: terminating requires a review window unless the operator opted out.
            if ($nextLevel === DunningState::LEVEL_TERMINATED && $this->reviewRequired($operator)) {
                $state->status = DunningState::STATUS_PENDING_TERMINATION_REVIEW;
                $state->review_due_at = now()->addHours($this->reviewWindowHours($operator));
                $state->save();
                $this->events->publish($this->stateEvent(BillingEvents::DUNNING_TERMINATION_PENDING, $state, ['reviewDueAt' => $state->review_due_at->toIso8601String()]));

                return true;
            }

            $state->save();
            if ($wasNone) {
                $this->events->publish($this->stateEvent(BillingEvents::SUBSCRIPTION_ENTERED_DUNNING, $state, ['triggeringEventType' => $state->triggering_event_type]));
            }
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_STAGE_ADVANCED, $state, ['level' => $nextLevel, 'decisionCode' => $decision['action'] ?? null, 'debt' => (string) $state->outstanding_debt_amount]));
            $this->applyAction($nextLevel, $subscription, $state, $decision);

            return true;
        });
    }

    /**
     * T-1 prepaid/prepayment entry: a CyclePaymentMissed event puts the
     * subscription into dunning at level 1 (the postpaid path enters via the
     * overdue-invoice scan).
     */
    public function enterFromCycleMissed(Subscription $subscription, float $debt): void
    {
        Context::setOperatorCode($subscription->operator_code);
        $state = DunningState::query()->firstOrNew(['operator_code' => $subscription->operator_code, 'account_id' => $subscription->account_id]);
        if ($state->exists && $state->current_level > 0) {
            return; // already in dunning
        }
        $state->fill([
            'subscription_id' => $subscription->subscription_id,
            'billing_mode' => $subscription->billing_mode ?? 'PREPAID',
            'triggering_event_type' => 'CyclePaymentMissed',
            'current_level' => DunningState::LEVEL_WARNING,
            'entered_level_at' => now(),
            'status' => DunningState::STATUS_ACTIVE,
            'outstanding_debt_amount' => $debt,
            'next_evaluation_at' => now()->addDay(),
            'last_scanned_at' => now(),
        ])->save();

        $this->events->publish($this->stateEvent(BillingEvents::SUBSCRIPTION_ENTERED_DUNNING, $state, ['triggeringEventType' => 'CyclePaymentMissed', 'debt' => (string) $debt]));
        $this->applyAction(DunningState::LEVEL_WARNING, $subscription, $state);
    }

    // ---- T-6 voluntary pause / resume ----

    public function pauseForVoluntaryPause(string $accountId): void
    {
        DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_ACTIVE)
            ->update(['status' => DunningState::STATUS_SUSPENDED_BY_PAUSE, 'next_evaluation_at' => null]);
    }

    public function resumeFromVoluntaryPause(string $accountId): void
    {
        DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_SUSPENDED_BY_PAUSE)
            ->update(['status' => DunningState::STATUS_ACTIVE, 'next_evaluation_at' => now()->addDay()]);
    }

    // ---- R-8 prepaid wallet-topup recovery ----

    public function recoverOnTopup(Subscription $subscription): void
    {
        $state = DunningState::query()->where('account_id', $subscription->account_id)
            ->whereIn('status', [DunningState::STATUS_ACTIVE, DunningState::STATUS_PENDING_TERMINATION_REVIEW])
            ->where('current_level', '>', 0)->first();
        if ($state) {
            // The cycle-close path consumes the wallet; if debt clears there, clear dunning.
            $this->clear($subscription->account_id);
        }
    }

    // ---- R-5 admin overrides (DUNNING_ADMIN) ----

    public function adminClear(string $accountId, ?string $actor = null): void
    {
        $this->clear($accountId);
    }

    public function hold(string $accountId, ?string $actor = null): void
    {
        DunningState::query()->where('account_id', $accountId)->update(['next_evaluation_at' => null]);
    }

    public function confirmTermination(string $accountId, ?string $actor = null): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)->first();
        if (! $state) {
            return;
        }
        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $state->update(['status' => DunningState::STATUS_ACTIVE]);
        $this->applyAction(DunningState::LEVEL_TERMINATED, $subscription, $state);
        $this->events->publish($this->stateEvent(BillingEvents::DUNNING_STAGE_ADVANCED, $state, ['level' => 4, 'decisionCode' => 'TERMINATE', 'confirmedBy' => $actor]));
    }

    public function extendReview(string $accountId, int $hours = 72): void
    {
        DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)
            ->update(['review_due_at' => now()->addHours($hours)]);
    }

    private function reviewRequired(string $operator): bool
    {
        $row = DB::table('dunning_config')->where('operator_code', $operator)->first();

        return (bool) ($row->pre_termination_review_required ?? true); // default TRUE (C-4)
    }

    private function reviewWindowHours(string $operator): int
    {
        return (int) (DB::table('dunning_config')->where('operator_code', $operator)->value('review_window_hours') ?? 72);
    }

    /** @param array<string,mixed> $extra */
    private function stateEvent(string $type, DunningState $state, array $extra): DomainEvent
    {
        return new DomainEvent(
            type: $type, topic: BillingEvents::TOPIC,
            payload: array_merge(['accountId' => $state->account_id, 'subscriptionId' => $state->subscription_id, 'level' => $state->current_level], $extra),
            aggregateType: 'DunningState', aggregateId: $state->dunning_id,
        );
    }

    /** @param array<string,mixed> $decision */
    private function applyAction(int $level, ?Subscription $subscription, DunningState $state, array $decision = []): void
    {
        if (! $subscription) {
            return;
        }
        $action = self::LEVEL_ACTION[$level] ?? 'NONE';
        $dunningRef = "dunning-{$state->account_id}-L{$level}";

        if ($action === 'WARN') {
            $this->notifications->send([
                'channel' => 'SMS', 'recipient' => $subscription->customer_id, 'template_code' => 'DUNNING_WARNING',
                'reference' => $state->account_id, 'body' => 'Your account has an overdue balance.',
            ]);

            return;
        }

        // Dunning-driven partial restriction (SUB-WF-RESTRICT-01, DUNNING_DRIVEN).
        if ($action === 'RESTRICT') {
            $code = $decision['restrictionCode'] ?? 'OUTGOING_VOICE_BARRED';
            $this->restrictions->add($subscription, $code, [
                'activationTrigger' => RestrictionService::TRIGGER_DUNNING,
                'dunningReference' => $dunningRef,
                'actorRole' => 'BILLING_INTERNAL',
                'actorUserId' => 'bil04-svc-account',
                'notes' => "Dunning level {$level}",
                'idempotencyKey' => "restrict-add-{$subscription->subscription_id}-{$code}-{$dunningRef}",
            ]);

            return;
        }

        // Drive the owning SUB-WF operation (idempotent by dunning level). Non-payment
        // suspension runs as SUSPEND_NP and carries the dunning context the operation
        // records on the pause-history row + the SuspendedForNonPayment event.
        if (in_array($action, ['SUSPEND_NP', 'TERMINATE'], true)) {
            $this->operations->trigger(
                subscription: $subscription,
                kind: $action,
                input: [
                    'reasonCode' => 'NON_PAYMENT',
                    'dunningLevel' => $level,
                    'dunningReasonCode' => $decision['dunningReasonCode'] ?? 'DUNNING_ESCALATION',
                    'dunningCycleReference' => $dunningRef,
                    'dunningEscalationLevel' => $level,
                    'outstandingDebtAmount' => (float) $state->outstanding_debt_amount,
                    'outstandingDebtCurrency' => $subscription->currency ?? 'KES',
                ],
                idempotencyKey: $dunningRef,
                actorRole: 'BILLING_INTERNAL',
            );

            if ($action === 'SUSPEND_NP') {
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

        // R-DM-4: BIL-04 resume-after-payment lifts any dunning-marked restrictions.
        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        if ($subscription) {
            $this->restrictions->removeDunningMarked($subscription);
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
