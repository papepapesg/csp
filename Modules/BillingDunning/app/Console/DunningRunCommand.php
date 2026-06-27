<?php

namespace Modules\Billing\Dunning\Console;

use Illuminate\Console\Command;
use Modules\Billing\Dunning\Services\DunningService;

/** BIL-04 dunning scanner — scheduled (and on-demand). */
class DunningRunCommand extends Command
{
    protected $signature = 'sophix:billing:dunning-run';

    protected $description = 'Scan overdue accounts and advance dunning escalation';

    public function handle(DunningService $dunning): int
    {
        $r = $dunning->scan();
        $this->info("dunning scanned {$r['scanned']} account(s), advanced {$r['advanced']}");

        return self::SUCCESS;
    }
}
