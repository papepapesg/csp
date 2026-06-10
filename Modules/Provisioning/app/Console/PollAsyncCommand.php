<?php

namespace Modules\Provisioning\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Provisioning\Services\ProvisioningService;

/** PROV-INT-01 §7.2 status worker: resolve ACCEPTED async commands (polled). */
class PollAsyncCommand extends Command
{
    protected $signature = 'sophix:provisioning:poll-async {--operator=}';

    protected $description = 'Poll accepted async provisioning commands for their final outcome';

    public function handle(ProvisioningService $service): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $r = $service->pollAsyncCommands($operator);
        $this->info("async poll: polled {$r['polled']}, resolved {$r['resolved']}");

        return self::SUCCESS;
    }
}
