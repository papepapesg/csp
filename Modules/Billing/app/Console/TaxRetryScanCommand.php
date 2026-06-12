<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\TaxSigningService;

/** BIL-02-TAX-01 retry scanner — re-submits due transient signing failures (F-2). */
class TaxRetryScanCommand extends Command
{
    protected $signature = 'sophix:billing:tax-retry-scan {--operator=}';

    protected $description = 'Re-submit transient SIGNING_FAILED tax invoices due for retry';

    public function handle(TaxSigningService $signing): int
    {
        $this->info('retry-scan processed '.$signing->retryScan($this->option('operator') ?: null).' tax invoice(s)');

        return self::SUCCESS;
    }
}
