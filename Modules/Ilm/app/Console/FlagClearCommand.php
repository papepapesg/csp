<?php

namespace Modules\Ilm\Console;

use Illuminate\Console\Command;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Ilm\Models\CustomerAccountFlag;
use Modules\Ilm\Services\AccountService;

/**
 * Ops safe-correction: clear one ACTIVE account flag via the EXISTING
 * AccountService::clearFlag operation (no EM-CFG-04 approval is involved). The
 * service marks the flag CLEARED, recomputes the derived attention_banner from the
 * remaining active flags, and emits AccountFlagCleared — a reversible, non-gated
 * correction. This wraps the service method directly, so it is break-glass console
 * use by ops with shell access; KYC decisions are NOT exposed here (they are
 * approval-gated and must go through the approval engine).
 */
class FlagClearCommand extends Command
{
    protected $signature = 'sophix:ilm:flag-clear
        {account : The account_id}
        {flag : The flag_code to clear}
        {--actor=cli-ops : Recorded as set_by on the cleared flag}';

    protected $description = 'Safe-correction: clear an active account flag and recompute the attention banner';

    public function handle(AccountService $accounts): int
    {
        $accountId = (string) $this->argument('account');
        $flagCode = (string) $this->argument('flag');
        $actor = (string) $this->option('actor');

        $account = CustomerAccount::query()->where('account_id', $accountId)->first();
        if (! $account) {
            $this->error("No account {$accountId}.");

            return self::FAILURE;
        }

        $flag = CustomerAccountFlag::query()->where('account_id', $accountId)->where('flag_code', $flagCode)->first();
        if (! $flag || $flag->state === CustomerAccountFlag::CLEARED) {
            $this->warn("Flag {$flagCode} on {$accountId} is not active — nothing to clear.");

            return self::SUCCESS;
        }

        $accounts->clearFlag($account, $flagCode, $actor);

        $account->refresh();
        $this->info("flag-clear: '{$flagCode}' cleared on {$accountId} by {$actor}.");
        $this->table(['Field', 'Value'], [
            ['attention_banner', $account->attention_banner ?? '—'],
            ['active_flags', implode(', ', $accounts->activeFlags($account)->pluck('flag_code')->all()) ?: '—'],
        ]);

        return self::SUCCESS;
    }
}
