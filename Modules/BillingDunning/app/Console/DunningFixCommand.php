<?php

namespace Modules\Billing\Dunning\Console;

use Illuminate\Console\Command;
use Modules\Billing\Dunning\Services\DunningService;

/**
 * Ops safe-correction: drive an account's dunning episode through the EXISTING
 * DunningService admin operations (the same ones behind the dunning.admin API
 * endpoints — no EM-CFG-04 approval is involved). Destructive actions require
 * --confirm. This wraps service methods directly, so it bypasses route
 * permissions; intended for break-glass console use by ops with shell access.
 */
class DunningFixCommand extends Command
{
    protected $signature = 'sophix:billing:dunning-fix
        {account : The account_id}
        {action : refresh-debt|clear|admin-clear|clear-without-payment|hold|advance|confirm-termination|force-terminate|extend-review}
        {--actor=cli-ops : Recorded as the acting user on the audit event}
        {--hours= : Hours, for hold/extend-review}
        {--confirm : Required for destructive actions (clear-without-payment, force-terminate)}';

    protected $description = 'Safe-correction: run a dunning admin op for one account (refresh-debt, clear, hold, advance, ...)';

    private const DESTRUCTIVE = ['clear-without-payment', 'force-terminate'];

    public function handle(DunningService $dunning): int
    {
        $account = (string) $this->argument('account');
        $action = (string) $this->argument('action');
        $actor = (string) $this->option('actor');
        $hours = $this->option('hours') !== null ? (int) $this->option('hours') : null;

        if (in_array($action, self::DESTRUCTIVE, true) && ! $this->option('confirm')) {
            $this->error("Action '{$action}' is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        match ($action) {
            'refresh-debt' => $dunning->refreshDebt($account),
            'clear' => $dunning->clear($account, 'CLEARED_FULLY_PAID', $actor),
            'admin-clear' => $dunning->adminClear($account, $actor),
            'clear-without-payment' => $dunning->clearWithoutPayment($account, $actor),
            'hold' => $dunning->hold($account, $actor, $hours),
            'advance' => $dunning->advance($account, $actor),
            'confirm-termination' => $dunning->confirmTermination($account, $actor),
            'force-terminate' => $dunning->forceTerminate($account, $actor),
            'extend-review' => $dunning->extendReview($account, $hours ?? 72),
            default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
        };

        $this->info("dunning-fix: '{$action}' applied to {$account} by {$actor}.");
        $this->call('sophix:billing:dunning-show', ['account' => $account]);

        return self::SUCCESS;
    }
}
