<?php

namespace Modules\Provisioning\Console;

use Illuminate\Console\Command;
use Modules\Provisioning\Models\ProvisioningCommand;

/**
 * Ops review: show one provisioning command's live state (read-only) — status,
 * execution mode, target, attempts, external ref and last error, so NOC can see
 * why a command is (or isn't) progressing before touching anything.
 */
class CommandShowCommand extends Command
{
    protected $signature = 'sophix:provisioning:command-show {command : The command_id (pcmd_...)}';

    protected $description = 'Review: show a provisioning command\'s state (read-only)';

    public function handle(): int
    {
        $commandId = (string) $this->argument('command');
        $command = ProvisioningCommand::query()->where('command_id', $commandId)->first();
        if (! $command) {
            $this->warn("No provisioning command {$commandId}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['command_id', $command->command_id],
            ['operator_code', $command->operator_code],
            ['broadcast_id', $command->broadcast_id ?? '—'],
            ['subscription_id', $command->subscription_id ?? '—'],
            ['service_ref', $command->service_ref ?? '—'],
            ['action', $command->action],
            ['target_code', $command->target_code],
            ['status', $command->status],
            ['execution_mode', $command->execution_mode ?? '—'],
            ['attempts', (string) $command->attempts],
            ['external_ref', $command->external_ref ?? '—'],
            ['desired_state', json_encode($command->desired_state)],
            ['observed_state', json_encode($command->observed_state)],
            ['last_error', $command->last_error ?? '—'],
            ['sent_at', (string) $command->sent_at],
            ['accepted_at', (string) $command->accepted_at],
            ['confirmed_at', (string) $command->confirmed_at],
        ]);

        if ($command->status === ProvisioningCommand::ACCEPTED) {
            $this->warn('Status ACCEPTED — the async status worker (sophix:provisioning:poll-async) resolves the final outcome.');
        }
        if (in_array($command->status, [ProvisioningCommand::FAILED, ProvisioningCommand::MISMATCH], true)) {
            $this->warn("Status {$command->status} — see sophix:provisioning:ops-fix for re-dispatch / reconcile options.");
        }

        return self::SUCCESS;
    }
}
