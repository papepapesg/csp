<?php

namespace Modules\Notification\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Notification\Services\RetryScheduler;

/** NOT-01 retry scanner — re-dispatches due PENDING_RETRY delivery attempts (F-2 / R-4). */
class RetryDispatchCommand extends Command
{
    protected $signature = 'sophix:notification:retry-dispatch {--operator=}';

    protected $description = 'Re-dispatch notification delivery attempts that are due for retry (R-NOT-01-F-2)';

    public function handle(RetryScheduler $scheduler): int
    {
        $operator = $this->option('operator') ?: null;
        if ($operator) {
            Context::setOperatorCode($operator);
        }
        $this->info('retried '.$scheduler->run($operator).' delivery attempt(s)');

        return self::SUCCESS;
    }
}
