<?php

namespace Modules\Notification\Console\Icn;

use Illuminate\Console\Command;
use Modules\Notification\Icn\Services\StaffNotificationSweeper;

/** ICN-01 delivery retry sweep — re-dispatches due FAILED/PENDING staff deliveries (R-ICN-01-D-7). */
class StaffRetryCommand extends Command
{
    protected $signature = 'sophix:icn:retry {--operator=}';

    protected $description = 'Re-dispatch ICN-01 staff deliveries due for retry';

    public function handle(StaffNotificationSweeper $sweeper): int
    {
        $this->info('retried '.$sweeper->retryDue($this->option('operator') ?: null).' notification(s)');

        return self::SUCCESS;
    }
}
