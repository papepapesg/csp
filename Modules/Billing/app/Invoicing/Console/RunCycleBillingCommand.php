<?php

namespace Modules\Billing\Invoicing\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Billing\Invoicing\Services\CycleBillingService;

/** BIL-02 cycle billing worker — settles unbilled rated events (invoice or wallet). */
class RunCycleBillingCommand extends Command
{
    protected $signature = 'sophix:billing:run-cycle {--operator=}';

    protected $description = 'Settle unbilled rated events at cycle close (BIL-02)';

    public function handle(CycleBillingService $service): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $r = $service->run($operator);
        $this->info("cycle-billed {$r['billed']} of {$r['subscriptions']} subscription(s)");

        return self::SUCCESS;
    }
}
