<?php

namespace Modules\Subscription\Console;

use Illuminate\Console\Command;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;

/**
 * Ops review: a one-glance health summary of the subscription operation ledger an
 * operator needs to watch (read-only). In-flight == final_state IS NULL
 * (SUB-WF-FRAMEWORK §6, same definition the framework and the timeout sweep use).
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:subscription:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of subscription operations/states needing ops attention (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $opScope = fn ($q) => $op ? $q->where('operator_code', $op) : $q;

        $inFlight = fn () => $opScope(SubscriptionOperation::query())->whereNull('final_state');

        $rows = [
            ['Operations in-flight (final_state IS NULL)', $inFlight()->count()],
            ['  · in-flight RESTRICT', $inFlight()->where('operation_kind', 'RESTRICT')->count()],
            ['  · in-flight REVERTING', $inFlight()->where('current_state', SubscriptionOperation::REVERTING)->count()],
            ['  · in-flight, started > 1h ago', $inFlight()->whereNotNull('started_at')->where('started_at', '<', now()->subHour())->count()],
            ['  · in-flight, never started (started_at IS NULL)', $inFlight()->whereNull('started_at')->count()],
            ['Operations FAILED (final_state)', $opScope(SubscriptionOperation::query())->where('final_state', SubscriptionOperation::FAILED)->count()],
            ['  · failed OPERATION_TIMEOUT', $opScope(SubscriptionOperation::query())->where('failure_reason_code', 'OPERATION_TIMEOUT')->count()],
            ['Subscriptions in a PENDING_* status', $opScope(Subscription::query())->where('status_code', 'like', 'PENDING_%')->count()],
            ['Subscriptions RESTRICTED', $opScope(Subscription::query())->where('status_code', Subscription::RESTRICTED)->count()],
            ['Subscriptions SUSPENDED', $opScope(Subscription::query())->where('status_code', Subscription::SUSPENDED)->count()],
        ];

        $this->info('Subscription ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Queue', 'Count'], $rows);
        $this->line('Drill in: sophix:subscription:operation-show <subscription> · sweep: sophix:subscription:operation-timeouts');

        return self::SUCCESS;
    }
}
