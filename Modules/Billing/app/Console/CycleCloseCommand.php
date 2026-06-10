<?php

namespace Modules\Billing\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Billing\Services\CycleCloseService;

/**
 * BIL-03 cycle-close scanner (R-BIL-03-E-1). Evaluates each active subscription
 * against its cycle anchor and closes the cycle (recurring fee + usage) at the
 * boundary. Scheduled every 30 minutes; safe to re-run (idempotent per cycle).
 */
class CycleCloseCommand extends Command
{
    protected $signature = 'sophix:billing:cycle-close {--operator=}';

    protected $description = 'Close due billing cycles (recurring fee + usage) per subscription (BIL-03)';

    public function handle(CycleCloseService $service): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $r = $service->scan($operator);
        $this->info("cycle-close: evaluated {$r['evaluated']}, closed {$r['closed']}, skipped {$r['skipped']}, failed {$r['failed']}");

        return self::SUCCESS;
    }
}
