<?php

namespace Modules\Billing\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Billing\Services\WalletService;

/** BIL-05 / R-W-9 wallet expiry sweep — zeroes expired wallet balances (daily). */
class WalletExpiryCommand extends Command
{
    protected $signature = 'sophix:wallet:expire {--operator=}';

    protected $description = 'Expire wallet balances past their validity window (R-W-9)';

    public function handle(WalletService $wallets): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $this->info('expired '.$wallets->expireBalances($operator).' wallet(s)');

        return self::SUCCESS;
    }
}
