<?php

namespace Modules\Billing\Dunning\Console;

use Illuminate\Console\Command;
use Modules\Billing\Dunning\Services\DunningService;

/** BIL-04 nightly archive sweep — flags CLEARED dunning states past retention as ARCHIVED (D-4). */
class ArchiveDunningStatesCommand extends Command
{
    protected $signature = 'sophix:billing:dunning-archive {--days=30}';

    protected $description = 'Archive CLEARED dunning states older than the retention threshold';

    public function handle(DunningService $dunning): int
    {
        $n = $dunning->archiveCleared((int) $this->option('days'));
        $this->info("archived {$n} dunning state(s)");

        return self::SUCCESS;
    }
}
