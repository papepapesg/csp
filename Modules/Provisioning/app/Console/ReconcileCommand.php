<?php

namespace Modules\Provisioning\Console;

use Illuminate\Console\Command;
use Modules\Provisioning\Models\ProvisioningTarget;
use Modules\Provisioning\Services\ReconciliationService;

/**
 * PROV-INT-01 reconciliation worker — polls each active target, compares observed
 * vs desired state, and opens review items for mismatches. Scheduled (and
 * on-demand). A polling worker per the SOPHIX worker model.
 */
class ReconcileCommand extends Command
{
    protected $signature = 'sophix:provisioning:reconcile {--target= : reconcile a single target_code}';

    protected $description = 'Reconcile network observed state against BSS desired state';

    public function handle(ReconciliationService $reconciliation): int
    {
        $target = $this->option('target');

        $targets = $target
            ? [$target]
            : ProvisioningTarget::query()->where('active', true)->pluck('target_code')->all();

        $totalMismatch = 0;
        foreach ($targets as $code) {
            $run = $reconciliation->run($code);
            $totalMismatch += $run->mismatch_count;
            $this->info("reconciled {$code}: desired={$run->desired_count} observed={$run->observed_count} mismatches={$run->mismatch_count}");
        }

        $this->info('reconciliation complete across '.count($targets)." target(s); {$totalMismatch} mismatch(es)");

        return self::SUCCESS;
    }
}
