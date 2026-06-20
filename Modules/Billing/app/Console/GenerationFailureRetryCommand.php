<?php

namespace Modules\Billing\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Billing\Services\CycleCloseService;
use Modules\Billing\Services\GenerationFailureService;

/**
 * BIL-02-GEN-01 rule group Q scanner (R-GEN-01-Q-2/Q-3). Re-attempts recoverable
 * invoice-generation failures parked in generation_failure_queue, routing each entry
 * to its owning generator by trigger_code. Without this the queue only ever grew —
 * failed cycle invoices were persisted but never retried. Scheduled every 15 minutes.
 */
class GenerationFailureRetryCommand extends Command
{
    protected $signature = 'sophix:billing:generation-retry {--operator=}';

    protected $description = 'Retry recoverable invoice-generation failures (BIL-02-GEN-01 rule group Q)';

    public function handle(GenerationFailureService $failures, CycleCloseService $cycles): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);

        $r = $failures->retryDue($operator, function (string $trigger, array $context, ?string $subscriptionId) use ($cycles): void {
            match ($trigger) {
                'CYCLE_POSTPAID' => $cycles->closeCycle($context['subscriptionId'] ?? $subscriptionId),
                default => throw new \RuntimeException("No generation-retry handler for trigger {$trigger}"),
            };
        });

        $this->info("generation-retry: retried {$r['retried']}, recovered {$r['recovered']}, gave up {$r['gaveUp']}");

        return self::SUCCESS;
    }
}
