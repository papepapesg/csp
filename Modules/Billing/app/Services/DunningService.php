<?php

namespace Modules\Billing\Services;
use Modules\Billing\Services\DunningProgramResolver;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Events\BillingEvents;
use Modules\Billing\Models\DunningProgram;
use Modules\Billing\Models\DunningState;
use Modules\Billing\Models\Invoice;
use Modules\Ilm\Services\AccountService;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Services\OperationFramework;
use Modules\Subscription\Services\RestrictionService;

/**
 * BIL-04 Dunning Engine. A per-Subscription state machine driven by the versioned
 * `dunning_program` catalog: the scanner advances levels at each level's configured
 * grace period and fires that level's `action_workflow_intent` (WARNING_ONLY /
 * RESTRICTION_ADD / SUSPEND_NP / TERMINATION); payment/topup events retreat or clear it.
 * The program version is pinned on the state row at entry (R-BIL-04-C-1), so policy edits
 * never disturb in-flight episodes. The money math, overdue detection, and the actual
 * restrict/suspend/terminate mutations live in their owning modules — BIL-04 orchestrates.
 */
class DunningService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly DunningProgramResolver $programs,
        private readonly OperationFramework $operations,
        private readonly RestrictionService $restrictions,
        private readonly AccountService $accounts,
    ) {}

    /**
     * Scan accounts with overdue debt and advance dunning where grace elapsed (R-BIL-04-E-1).
     *
     * @return array{scanned:int, advanced:int}
     */
    public function scan(): array
    {
        $accounts = Invoice::query()
            ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])
            ->where('amount_due', '>', 0)
            ->where('due_date', '<', now())
            ->select('account_id', 'operator_code')
            ->selectRaw('SUM(amount_due) as debt')
            ->selectRaw('MIN(due_date) as oldest_due')
            ->groupBy('account_id', 'operator_code')
            ->limit(500) // R-BIL-04-E-4 batch cap
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

        $state = DunningState::query()->where('operator_code', $operator)->where('account_id', $accountId)->lockForUpdate()->first()
            ?? new DunningState(['operator_code' => $operator, 'account_id' => $accountId, 'current_level' => DunningState::LEVEL_NONE, 'entered_level_at' => now(), 'status' => DunningState::STATUS_ACTIVE]);
        $isNew = ! $state->exists;

        // T-5/T-6: a paused, in-review, or recovery-failed episode is not advanced by the scanner.
        if (in_array($state->status, [DunningState::STATUS_SUSPENDED_BY_PAUSE, DunningState::STATUS_PENDING_TERMINATION_REVIEW, DunningState::STATUS_RECOVERY_FAILED, DunningState::STATUS_ARCHIVED], true)) {
            return false;
        }
        // E-2: at-most-daily cadence.
        if ($state->exists && $state->next_evaluation_at && $state->next_evaluation_at->isFuture()) {
            return false;
        }

        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $billingMode = $subscription?->billing_mode ?? DunningProgram::POSTPAID;
        $state->subscription_id = $subscription?->subscription_id;
        $state->billing_mode = $billingMode;

        // Resolve + pin the program on first entry; thereafter use the pinned version.
        $program = $this->pinProgram($state, $operator, $billingMode);
        if (! $program) {
            return false; // no policy for this operator/mode — nothing to drive
        }

        // T-2: debt grew at the same level → DebtIncreased, no level change.
        if (! $isNew && $debt > (float) $state->outstanding_debt_amount && $state->current_level > 0) {
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_DEBT_INCREASED, $state, ['debt' => (string) $debt]));
        }
        $state->outstanding_debt_amount = $debt;
        $state->outstanding_debt_currency = $subscription?->currency ?? 'KES';
        $state->triggering_event_type ??= 'INVOICE_OVERDUE';
        $state->last_scanned_at = now();
        $state->next_evaluation_at = now()->addDay(); // E-2

        // T-3: grace must have elapsed AND debt still > 0.
        $graceDays = $state->current_level === DunningState::LEVEL_NONE ? 0 : $program->graceDays($state->current_level);
        // R-ILM-F-3: an affects_dunning account flag (e.g. NPD) escalates faster — waive grace.
        if ($graceDays > 0 && $this->accounts->hasDunningAccelerantFlag($accountId, $operator)) {
            $graceDays = 0;
        }
        $daysAtLevel = $state->entered_level_at ? (int) abs(now()->diffInDays($state->entered_level_at)) : 0;
        if ($daysAtLevel < $graceDays) {
            $state->save();

            return false;
        }

        // D-2 monotonic: advance exactly one level, never skip.
        $nextLevel = $state->current_level + 1;
        if ($nextLevel > $program->maxLevel()) {
            $state->save();

            return false; // already at terminal level
        }

        // T-4: terminating requires a review window unless the program opted out.
        if ($program->actionIntent($nextLevel) === DunningProgram::TERMINATION && $program->pre_termination_review_required) {
            $state->status = DunningState::STATUS_PENDING_TERMINATION_REVIEW;
            $state->review_due_at = now()->addHours($this->reviewWindowHours($operator));
            $state->save();
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_TERMINATION_PENDING, $state, ['reviewDueAt' => $state->review_due_at->toIso8601String()]));

            return true;
        }

        // Commit the level advance + episode/stage events, THEN drive the workflow action
        // outside the transaction (a RESTRICT/SUSPEND call must not roll back the advance, and
        // RESTRICT is serialized per Subscription by SUB-WF — applied tolerantly, R-BIL-04-WF-3).
        $wasNone = $state->current_level === DunningState::LEVEL_NONE;
        DB::transaction(function () use ($state, $nextLevel, $program, $wasNone) {
            $state->current_level = $nextLevel;
            $state->entered_level_at = now();
            if ($wasNone) {
                $state->entered_dunning_at = now();
            }
            $state->save();

            if ($wasNone) {
                $this->events->publish($this->stateEvent(BillingEvents::SUBSCRIPTION_ENTERED_DUNNING, $state, ['triggeringEventType' => $state->triggering_event_type]));
            }
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_STAGE_ADVANCED, $state, [
                'level' => $nextLevel, 'levelName' => $program->levelDef($nextLevel)['name'] ?? null, 'debt' => (string) $state->outstanding_debt_amount,
            ]));
        });

        $this->applyLevelAction($program, $nextLevel, $subscription, $state);

        return true;
    }

    /**
     * T-1 prepaid/prepayment entry: a CyclePaymentMissed event enters dunning at level 1
     * (the postpaid path enters via the overdue-invoice scan).
     */
    public function enterFromCycleMissed(Subscription $subscription, float $debt, ?string $cycleRef = null): void
    {
        Context::setOperatorCode($subscription->operator_code);
        $state = DunningState::query()->where('operator_code', $subscription->operator_code)->where('account_id', $subscription->account_id)->first();
        if ($state && $state->current_level > 0) {
            return; // already in dunning
        }
        $state ??= new DunningState(['operator_code' => $subscription->operator_code, 'account_id' => $subscription->account_id]);
        $billingMode = $subscription->billing_mode ?? DunningProgram::PREPAID;
        $program = $this->programs->resolve($subscription->operator_code, $billingMode);
        if (! $program) {
            return;
        }

        $state->fill([
            'subscription_id' => $subscription->subscription_id,
            'billing_mode' => $billingMode,
            'dunning_program_ref' => $program->code,
            'dunning_program_version' => $program->version,
            'triggering_event_type' => 'CYCLE_PAYMENT_MISSED',
            'triggering_event_ref' => $cycleRef,
            'current_level' => DunningState::LEVEL_WARNING,
            'entered_level_at' => now(),
            'entered_dunning_at' => now(),
            'status' => DunningState::STATUS_ACTIVE,
            'outstanding_debt_amount' => $debt,
            'outstanding_debt_currency' => $subscription->currency ?? 'KES',
            'next_evaluation_at' => now()->addDay(),
            'last_scanned_at' => now(),
        ])->save();

        $this->events->publish($this->stateEvent(BillingEvents::SUBSCRIPTION_ENTERED_DUNNING, $state, ['triggeringEventType' => 'CYCLE_PAYMENT_MISSED', 'debt' => (string) $debt]));
        $this->applyLevelAction($program, DunningState::LEVEL_WARNING, $subscription, $state);
    }

    /** Resolve + pin the program on first entry; reload the pinned version thereafter. */
    private function pinProgram(DunningState $state, string $operator, string $billingMode): ?DunningProgram
    {
        if ($state->dunning_program_ref && $state->dunning_program_version) {
            return $state->program();
        }
        $program = $this->programs->resolve($operator, $billingMode);
        if ($program) {
            $state->dunning_program_ref = $program->code;
            $state->dunning_program_version = $program->version;
        }

        return $program;
    }

    /**
     * Apply a level's action_workflow_intent with its action_payload. RESTRICTION_ADD applies
     * each code in array order and records them on applied_restriction_codes for recovery (R-4).
     */
    private function applyLevelAction(DunningProgram $program, int $level, ?Subscription $subscription, DunningState $state): void
    {
        $intent = $program->actionIntent($level);
        $payload = $program->actionPayload($level);
        $dunningRef = "dunning-{$state->account_id}-L{$level}";

        // WARNING_ONLY does no workflow work — the customer-facing notice is owned by NOT-01,
        // which routes the already-emitted DunningStageAdvanced event per the operator's routing
        // rules and the customer's channel preferences (R-BIL-04 scope: "BIL-04 emits events at
        // each level transition; notification consumes"). Channels are config, never hardcoded.
        if ($intent === DunningProgram::WARNING_ONLY || ! $subscription) {
            return;
        }

        if ($intent === DunningProgram::RESTRICTION_ADD) {
            $this->applyRestrictions($subscription, $state, (array) ($payload['restriction_codes'] ?? []), $level, $dunningRef);

            return;
        }

        if (in_array($intent, [DunningProgram::SUSPEND_NP, DunningProgram::TERMINATION], true)) {
            $kind = $intent === DunningProgram::SUSPEND_NP ? 'SUSPEND_NP' : 'TERMINATE';
            $this->operations->trigger(
                subscription: $subscription,
                kind: $kind,
                input: [
                    'reasonCode' => 'NON_PAYMENT',
                    'dunningLevel' => $level,
                    'dunningReasonCode' => $payload['reason_code'] ?? 'DUNNING_ESCALATION',
                    'equipmentDisposition' => $payload['equipment_disposition'] ?? null,
                    'dunningCycleReference' => $dunningRef,
                    'dunningEscalationLevel' => $level,
                    'outstandingDebtAmount' => (float) $state->outstanding_debt_amount,
                    'outstandingDebtCurrency' => $state->outstanding_debt_currency ?? 'KES',
                ],
                idempotencyKey: $dunningRef,
                actorRole: 'BILLING_INTERNAL',
            );

            if ($intent === DunningProgram::SUSPEND_NP) {
                $this->events->publish(new DomainEvent(
                    type: BillingEvents::SUBSCRIPTION_SUSPENDED_NP, topic: BillingEvents::TOPIC,
                    payload: ['subscriptionId' => $subscription->subscription_id, 'accountId' => $state->account_id],
                    aggregateType: 'Subscription', aggregateId: $subscription->subscription_id,
                ));
            }
            if ($intent === DunningProgram::TERMINATION) {
                $this->archive($state, 'TERMINATED');
            }
        }
    }

    /**
     * Apply a level's restriction codes (R-4), each its own SUB-WF-RESTRICT-01 ADD call with
     * its own idempotency key. SUB-WF allows one in-flight RESTRICT per Subscription, so a code
     * whose ADD is currently in flight is skipped this pass (a no-op via idempotency / conflict)
     * and reconciled on a later pass. Successfully-applied codes are recorded for recovery.
     *
     * @param  list<string>  $codes
     */
    private function applyRestrictions(Subscription $subscription, DunningState $state, array $codes, int $level, string $dunningRef): void
    {
        $applied = $state->applied_restriction_codes ?? [];
        foreach ($codes as $code) {
            if (in_array($code, $applied, true)) {
                continue;
            }
            try {
                $this->restrictions->add($subscription, $code, [
                    'activationTrigger' => RestrictionService::TRIGGER_DUNNING,
                    'dunningReference' => $dunningRef,
                    'originReason' => "DUNNING_LEVEL_{$level}",
                    'actorRole' => 'BILLING_INTERNAL',
                    'actorUserId' => 'bil04-svc-account',
                    'notes' => "Dunning level {$level}",
                    'idempotencyKey' => "restrict-add-{$subscription->subscription_id}-{$code}-{$dunningRef}",
                ]);
                $applied[] = $code;
            } catch (\Throwable) {
                // RESTRICT already in flight for this Subscription — reconcile on a later pass.
            }
        }
        $state->applied_restriction_codes = array_values(array_unique($applied));
        $state->save();
    }

    /**
     * Reconcile any not-yet-applied restriction codes for a state currently at a RESTRICTION_ADD
     * level. Called by the scanner each pass (bypassing the daily cadence) so a multi-code level
     * fully applies as the per-Subscription RESTRICT serialization allows.
     */
    public function reconcileRestrictions(string $accountId): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_ACTIVE)->first();
        if (! $state || ! $state->dunning_program_ref) {
            return;
        }
        $program = $state->program();
        if (! $program || $program->actionIntent($state->current_level) !== DunningProgram::RESTRICTION_ADD) {
            return;
        }
        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        if ($subscription) {
            $this->applyRestrictions($subscription, $state, (array) ($program->actionPayload($state->current_level)['restriction_codes'] ?? []), $state->current_level, "dunning-{$accountId}-L{$state->current_level}");
        }
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
            $this->clear($subscription->account_id);
        }
    }

    // ---- R-5 admin overrides (DUNNING_ADMIN) ----

    public function adminClear(string $accountId, ?string $actor = null): void
    {
        $this->clear($accountId, 'ADMIN_CLEARED', $actor);
    }

    /** R-5(c) clear-without-payment: write off the debt and recover the subscription. */
    public function clearWithoutPayment(string $accountId, ?string $actor = null): void
    {
        $this->emitAdminOverride($accountId, 'CLEAR_WITHOUT_PAYMENT', $actor);
        $this->clear($accountId, 'ADMIN_CLEARED', $actor);
    }

    public function hold(string $accountId, ?string $actor = null, ?int $hours = null): void
    {
        DunningState::query()->where('account_id', $accountId)
            ->update(['next_evaluation_at' => $hours ? now()->addHours($hours) : null]);
        $this->emitAdminOverride($accountId, 'HOLD', $actor);
    }

    /** R-5(b) skip-to-next-level: force one advance ahead of schedule by zeroing the grace clock. */
    public function advance(string $accountId, ?string $actor = null): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_ACTIVE)->first();
        if (! $state) {
            return;
        }
        $state->update(['entered_level_at' => now()->subYears(1), 'next_evaluation_at' => null]);
        $this->emitAdminOverride($accountId, 'ADVANCE', $actor);
        $debt = (float) $state->outstanding_debt_amount;
        $this->assessAccount($accountId, $state->operator_code, $debt > 0 ? $debt : 1, now()->subDays(60)->toDateString());
    }

    public function confirmTermination(string $accountId, ?string $actor = null): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)->first();
        if (! $state) {
            return;
        }
        $program = $state->program();
        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $state->update(['status' => DunningState::STATUS_ACTIVE, 'current_level' => DunningState::LEVEL_TERMINATED, 'entered_level_at' => now()]);
        $this->events->publish($this->stateEvent(BillingEvents::DUNNING_STAGE_ADVANCED, $state, ['level' => 4, 'confirmedBy' => $actor]));
        if ($program && $subscription) {
            $this->applyLevelAction($program, DunningState::LEVEL_TERMINATED, $subscription, $state);
        }
    }

    /** Force terminate from review without waiting (admin). */
    public function forceTerminate(string $accountId, ?string $actor = null): void
    {
        $this->emitAdminOverride($accountId, 'FORCE_TERMINATE', $actor);
        $this->confirmTermination($accountId, $actor);
    }

    public function extendReview(string $accountId, int $hours = 72): void
    {
        DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_PENDING_TERMINATION_REVIEW)
            ->update(['review_due_at' => now()->addHours($hours)]);
    }

    /** POST /refresh-debt: re-pull the outstanding debt for a state from open invoices. */
    public function refreshDebt(string $accountId): ?DunningState
    {
        $state = DunningState::query()->where('account_id', $accountId)->first();
        if (! $state) {
            return null;
        }
        $debt = (float) Invoice::query()->where('account_id', $accountId)
            ->whereIn('status', [Invoice::OPEN, Invoice::PARTIALLY_PAID, Invoice::OVERDUE])->sum('amount_due');
        if ($debt <= 0 && $state->current_level > 0 && $state->status === DunningState::STATUS_ACTIVE) {
            $this->clear($accountId);

            return $state->refresh();
        }
        $state->update(['outstanding_debt_amount' => $debt]);

        return $state;
    }

    private function reviewWindowHours(string $operator): int
    {
        return (int) (DB::table('dunning_config')->where('operator_code', $operator)->value('review_window_hours') ?? 72);
    }

    /**
     * Clear dunning once debt is settled. Recovery reverses the applied actions in reverse:
     * level >= 2 removes the dunning-applied restrictions; level 3 resumes service from
     * non-payment suspension (R-BIL-04-R-1/R-2/R-3). A failed recovery flags RECOVERY_FAILED.
     */
    public function clear(string $accountId, string $archiveReason = 'CLEARED_FULLY_PAID', ?string $actor = null): void
    {
        $state = DunningState::query()->where('account_id', $accountId)->where('status', DunningState::STATUS_ACTIVE)->first();
        if (! $state) {
            return;
        }
        $subscription = Subscription::query()->where('account_id', $accountId)->first();
        $level = $state->current_level;

        try {
            if ($subscription && $level >= DunningState::LEVEL_RESTRICTED) {
                $this->restrictions->removeDunningMarked($subscription); // R-2 reverse-order removal
            }
            if ($subscription && $level >= DunningState::LEVEL_SUSPENDED) {
                $this->operations->trigger(
                    subscription: $subscription, kind: 'RESUME',
                    input: ['trigger' => 'DUNNING_RESUME', 'reasonCode' => 'DUNNING_RESUME'],
                    idempotencyKey: "dunning-resume-{$subscription->subscription_id}-{$state->entered_level_at?->timestamp}",
                    actorRole: 'BILLING_INTERNAL',
                ); // R-3 resume from suspension
            }
        } catch (\Throwable $e) {
            $state->update(['status' => DunningState::STATUS_RECOVERY_FAILED, 'last_workflow_failure_code' => substr($e->getMessage(), 0, 250), 'last_workflow_failure_at' => now(), 'workflow_failure_attempts' => $state->workflow_failure_attempts + 1]);
            $this->events->publish($this->stateEvent(BillingEvents::DUNNING_RECOVERY_FAILED, $state, ['error' => $e->getMessage()]));

            return;
        }

        $state->update([
            'current_level' => DunningState::LEVEL_NONE, 'status' => DunningState::STATUS_CLEARED,
            'outstanding_debt_amount' => 0, 'applied_restriction_codes' => [], 'cleared_at' => now(), 'entered_level_at' => now(),
        ]);
        $this->events->publish(new DomainEvent(
            type: BillingEvents::DUNNING_CLEARED, topic: BillingEvents::TOPIC,
            payload: ['accountId' => $accountId, 'subscriptionId' => $state->subscription_id],
            aggregateType: 'DunningState', aggregateId: $state->dunning_id,
        ));
        $this->archive($state, $archiveReason);
    }

    /** D-4: snapshot a settled/terminated episode into the archive (hard delete is never done). */
    public function archive(DunningState $state, string $reason): void
    {
        DB::table('dunning_state_archive')->updateOrInsert(
            ['dunning_id' => $state->dunning_id],
            [
                'operator_code' => $state->operator_code, 'account_id' => $state->account_id, 'subscription_id' => $state->subscription_id,
                'billing_mode' => $state->billing_mode, 'dunning_program_ref' => $state->dunning_program_ref, 'dunning_program_version' => $state->dunning_program_version,
                'current_level' => $state->current_level, 'outstanding_debt_amount' => $state->outstanding_debt_amount, 'outstanding_debt_currency' => $state->outstanding_debt_currency,
                'triggering_event_type' => $state->triggering_event_type, 'triggering_event_ref' => $state->triggering_event_ref,
                'applied_restriction_codes' => json_encode($state->applied_restriction_codes ?? []), 'status' => $state->status, 'archive_reason' => $reason,
                'entered_dunning_at' => $state->entered_dunning_at, 'cleared_at' => $state->cleared_at, 'archived_at' => now(),
                'snapshot' => json_encode($state->toArray()), 'created_at' => now(), 'updated_at' => now(),
            ],
        );
        $state->update(['archived_at' => now()]);
    }

    /**
     * Nightly archive sweep (DunningArchiveScanner): flag CLEARED/terminated states older than
     * the retention threshold as ARCHIVED. The snapshot already lives in dunning_state_archive
     * (written at clear/terminate); the live row is retained but marked, never hard-deleted (D-4).
     */
    public function archiveCleared(int $olderThanDays = 30, int $limit = 1000): int
    {
        $rows = DunningState::query()
            ->where('status', DunningState::STATUS_CLEARED)
            ->whereNotNull('cleared_at')->where('cleared_at', '<', now()->subDays($olderThanDays))
            ->whereNull('archived_at')->limit($limit)->get();

        foreach ($rows as $state) {
            $this->archive($state, 'CLEARED_FULLY_PAID');
            $state->update(['status' => DunningState::STATUS_ARCHIVED]);
        }

        return $rows->count();
    }

    /** @param array<string,mixed> $extra */
    private function stateEvent(string $type, DunningState $state, array $extra): DomainEvent
    {
        return new DomainEvent(
            type: $type, topic: BillingEvents::TOPIC,
            payload: array_merge(['accountId' => $state->account_id, 'subscriptionId' => $state->subscription_id, 'level' => $state->current_level, 'programRef' => $state->dunning_program_ref, 'programVersion' => $state->dunning_program_version], $extra),
            aggregateType: 'DunningState', aggregateId: $state->dunning_id,
        );
    }

    private function emitAdminOverride(string $accountId, string $action, ?string $actor): void
    {
        $this->events->publish(new DomainEvent(
            type: BillingEvents::DUNNING_ADMIN_OVERRIDE, topic: BillingEvents::TOPIC,
            payload: ['accountId' => $accountId, 'action' => $action, 'actor' => $actor],
            aggregateType: 'DunningState', aggregateId: $accountId,
        ));
    }
}
