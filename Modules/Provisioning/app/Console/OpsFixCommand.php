<?php

namespace Modules\Provisioning\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Provisioning\Services\ReconciliationService;

/**
 * Ops safe-correction: drive provisioning recovery through EXISTING service
 * operations — the same async-poll / dispatch / reconcile entry points the
 * scheduled workers use (no EM-CFG-04 approval is involved). Re-dispatch
 * re-pushes to the network, so it requires --confirm. This wraps service
 * methods directly; intended for break-glass console use by ops with shell
 * access. Approval-gated force-sync is NOT exposed here (use the dunning.admin
 * /force-sync API path instead).
 */
class OpsFixCommand extends Command
{
    protected $signature = 'sophix:provisioning:ops-fix
        {action : poll-async|reconcile|redispatch}
        {--operator= : Operator code (poll-async/reconcile; defaults to sophix.default_operator)}
        {--target= : target_code, for reconcile (default: that operator desired states)}
        {--command= : command_id (pcmd_...), required for redispatch}
        {--confirm : Required for destructive actions (redispatch)}';

    protected $description = 'Safe-correction: run a provisioning ops op (poll-async, reconcile, redispatch)';

    private const DESTRUCTIVE = ['redispatch'];

    public function handle(ProvisioningService $provisioning, ReconciliationService $reconciliation): int
    {
        $action = (string) $this->argument('action');

        if (in_array($action, self::DESTRUCTIVE, true) && ! $this->option('confirm')) {
            $this->error("Action '{$action}' re-pushes to the network; re-run with --confirm.");

            return self::FAILURE;
        }

        switch ($action) {
            case 'poll-async':
                $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
                Context::setOperatorCode($operator);
                $r = $provisioning->pollAsyncCommands($operator);
                $this->info("poll-async ({$operator}): polled {$r['polled']}, resolved {$r['resolved']}");
                break;

            case 'reconcile':
                $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
                Context::setOperatorCode($operator);
                $run = $reconciliation->run($this->option('target'), $operator);
                $this->info("reconcile run {$run->run_id}: desired={$run->desired_count} observed={$run->observed_count} mismatches={$run->mismatch_count}");
                break;

            case 'redispatch':
                $commandId = (string) $this->option('command');
                if ($commandId === '') {
                    $this->error('redispatch requires --command=<command_id>.');

                    return self::FAILURE;
                }
                $command = ProvisioningCommand::query()->where('command_id', $commandId)->first();
                if (! $command) {
                    $this->error("No provisioning command {$commandId}.");

                    return self::FAILURE;
                }
                Context::setOperatorCode((string) $command->operator_code);
                $command = $provisioning->dispatch($command);
                $this->info("redispatch {$commandId}: now {$command->status} (attempt {$command->attempts}).");
                $this->call('sophix:provisioning:command-show', ['command' => $commandId]);
                break;

            default:
                throw new \InvalidArgumentException("Unknown action '{$action}'.");
        }

        return self::SUCCESS;
    }
}
