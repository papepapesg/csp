<?php

namespace Modules\Notification\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Notification\Icn\Services\StaffNotificationSweeper;
use Modules\Notification\Services\RenderRetryService;
use Modules\Notification\Services\RetryScheduler;

/**
 * Ops safe-correction: drive the EXISTING notification sweep/retry services from the
 * console — the same drain logic the scheduled scanners run (no approval gate). Each
 * action wraps one service method directly. 'staff-expire' moves notifications past
 * their ack window to EXPIRED (and suppresses their open deliveries), so it is
 * destructive and requires --confirm.
 */
class RetryFixCommand extends Command
{
    protected $signature = 'sophix:notification:retry-fix
        {action : customer-retry|render-retry|staff-retry|staff-expire}
        {--operator= : Scope to one operator code (default: all)}
        {--confirm : Required for destructive actions (staff-expire)}';

    protected $description = 'Safe-correction: run a notification drain op (customer-retry, render-retry, staff-retry, staff-expire)';

    private const DESTRUCTIVE = ['staff-expire'];

    public function handle(RetryScheduler $retry, RenderRetryService $render, StaffNotificationSweeper $sweeper): int
    {
        $action = (string) $this->argument('action');
        $operator = $this->option('operator') ?: null;

        if (in_array($action, self::DESTRUCTIVE, true) && ! $this->option('confirm')) {
            $this->error("Action '{$action}' is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        if ($operator) {
            Context::setOperatorCode($operator);
        }

        $count = match ($action) {
            'customer-retry' => $retry->run($operator),
            'render-retry' => $render->run($operator),
            'staff-retry' => $sweeper->retryDue($operator),
            'staff-expire' => $sweeper->expireWindow($operator),
            default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
        };

        $this->info("retry-fix: '{$action}' processed {$count} item(s)".($operator ? " for operator {$operator}" : '').'.');

        return self::SUCCESS;
    }
}
