<?php

namespace Modules\Notification\Console\Icn;

use Illuminate\Console\Command;
use Modules\Notification\Icn\Services\StaffNotificationSweeper;

/** ICN-01 ack-window expiry sweep — EXPIRES staff notifications past their ack window (R-ICN-01-D-3). */
class StaffExpireCommand extends Command
{
    protected $signature = 'sophix:icn:expire {--operator=}';

    protected $description = 'Expire ICN-01 staff notifications past their ack window';

    public function handle(StaffNotificationSweeper $sweeper): int
    {
        $this->info('expired '.$sweeper->expireWindow($this->option('operator') ?: null).' notification(s)');

        return self::SUCCESS;
    }
}
