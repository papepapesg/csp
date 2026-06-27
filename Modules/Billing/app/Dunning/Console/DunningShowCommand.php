<?php

namespace Modules\Billing\Dunning\Console;

use Illuminate\Console\Command;
use Modules\Billing\Dunning\Models\DunningState;

/**
 * Ops review: show one account's live dunning episode (read-only) — the level, status,
 * debt, cadence clock and applied restrictions, so support can see why an account is
 * (or isn't) being chased before touching anything.
 */
class DunningShowCommand extends Command
{
    protected $signature = 'sophix:billing:dunning-show {account : The account_id}';

    protected $description = 'Review: show an account\'s dunning state (read-only)';

    private const LEVELS = [0 => 'NONE', 1 => 'WARNING', 2 => 'RESTRICTED', 3 => 'SUSPENDED', 4 => 'TERMINATED'];

    public function handle(): int
    {
        $accountId = $this->argument('account');
        $state = DunningState::query()->where('account_id', $accountId)->first();
        if (! $state) {
            $this->warn("No dunning state for account {$accountId} (not in dunning).");

            return self::SUCCESS;
        }

        $level = (int) $state->current_level;
        $this->table(['Field', 'Value'], [
            ['account_id', $state->account_id],
            ['operator_code', $state->operator_code],
            ['current_level', $level.' ('.(self::LEVELS[$level] ?? '?').')'],
            ['status', $state->status],
            ['outstanding_debt', $state->outstanding_debt_amount.' '.($state->outstanding_debt_currency ?? '')],
            ['entered_level_at', (string) $state->entered_level_at],
            ['next_evaluation_at', (string) $state->next_evaluation_at],
            ['review_due_at', (string) $state->review_due_at],
            ['program', $state->dunning_program_ref.' v'.$state->dunning_program_version],
            ['applied_restrictions', implode(', ', (array) ($state->applied_restriction_codes ?? [])) ?: '—'],
        ]);

        if (in_array($state->status, [DunningState::STATUS_SUSPENDED_BY_PAUSE, DunningState::STATUS_PENDING_TERMINATION_REVIEW, DunningState::STATUS_RECOVERY_FAILED, DunningState::STATUS_ARCHIVED], true)) {
            $this->warn("Status {$state->status} — the daily scanner will NOT advance this episode.");
        }

        return self::SUCCESS;
    }
}
