<?php

namespace Modules\Billing\Invoicing\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Billing\Invoicing\Services\ProFormaService;

/** BIL-02-GEN-01 Generator 3 — pro-forma pre-cycle scanner (PREPAID, daily). */
class ProFormaScanCommand extends Command
{
    protected $signature = 'sophix:billing:pro-forma {--operator=}';

    protected $description = 'Generate pre-cycle pro-forma documents for prepaid subscriptions';

    public function handle(ProFormaService $service): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $r = $service->scan($operator);
        $this->info("pro-forma: scanned {$r['scanned']}, generated {$r['generated']}");

        return self::SUCCESS;
    }
}
