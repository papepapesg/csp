<?php

namespace Modules\Billing\Tax\Console;

use Illuminate\Console\Command;
use Modules\Billing\Tax\Services\TaxSigningService;

/** BIL-02-TAX-01 signing scanner — submits GENERATED tax invoices to the gateway (S-2). */
class TaxSignScanCommand extends Command
{
    protected $signature = 'sophix:billing:tax-sign-scan {--operator=}';

    protected $description = 'Submit GENERATED tax invoices to the tax-authority gateway';

    public function handle(TaxSigningService $signing): int
    {
        $this->info('signed-scan processed '.$signing->signScan($this->option('operator') ?: null).' tax invoice(s)');

        return self::SUCCESS;
    }
}
