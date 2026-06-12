<?php

namespace Modules\Notification\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Notification\Services\RenderRetryService;

/** NOT-01 render retry scanner — re-attempts queued render failures (R-NOT-01-D-7). */
class RetryRenderCommand extends Command
{
    protected $signature = 'sophix:notification:retry-render {--operator=}';

    protected $description = 'Re-attempt render_failure_queue entries due for retry (R-NOT-01-D-7)';

    public function handle(RenderRetryService $service): int
    {
        $operator = $this->option('operator') ?: null;
        if ($operator) {
            Context::setOperatorCode($operator);
        }
        $this->info('re-attempted '.$service->run($operator).' render failure(s)');

        return self::SUCCESS;
    }
}
