<?php

namespace Modules\Billing\Console;

use Illuminate\Console\Command;
use Modules\Billing\Services\MediationRatingService;

/** RAT-01 offline rating worker — rates all pending mediated usage. */
class RateUsageCommand extends Command
{
    protected $signature = 'sophix:billing:rate-usage {--operator=}';

    protected $description = 'Rate pending mediated usage records (RAT-01)';

    public function handle(MediationRatingService $service): int
    {
        $r = $service->ratePending($this->option('operator') ?: config('sophix.default_operator', 'WIK'));
        $this->info("rated {$r['rated']} usage record(s)");

        return self::SUCCESS;
    }
}
