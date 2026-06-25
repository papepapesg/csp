<?php

namespace Modules\Workforce\Console;

use Illuminate\Console\Command;
use Modules\Workforce\Models\ContractorSlotCommitment;
use Modules\Workforce\Services\ContractorAvailabilityService;

/**
 * Ops safe-correction: RELEASE every ACTIVE slot commitment held for one work order,
 * restoring the contractor's capacity, via the EXISTING
 * ContractorAvailabilityService::releaseForWorkOrder() — the same method the WO
 * lifecycle listener calls on cancellation (R-EM-CS-7, no approval involved). Use it
 * as break-glass when a WO was cancelled/abandoned but its commitments are still
 * pinning capacity. Wrapping the service directly bypasses route permissions, so this
 * is intended for console use by ops with shell access. Destructive: requires --confirm.
 */
class ReleaseWoCommand extends Command
{
    protected $signature = 'sophix:workforce:release-wo
        {wo : The wo_id whose ACTIVE commitments to release}
        {--confirm : Required: releasing commitments restores capacity and is destructive}';

    protected $description = 'Safe-correction: release a work order\'s ACTIVE slot commitments (restore capacity)';

    public function handle(ContractorAvailabilityService $availability): int
    {
        $woId = (string) $this->argument('wo');

        $pending = ContractorSlotCommitment::query()
            ->where('wo_id', $woId)->where('status', ContractorSlotCommitment::ACTIVE)->count();

        if ($pending === 0) {
            $this->info("No ACTIVE commitments for WO {$woId}; nothing to release.");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error("Releasing {$pending} ACTIVE commitment(s) for WO {$woId} is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        $released = $availability->releaseForWorkOrder($woId);
        $this->info("release-wo: released {$released} commitment(s) for WO {$woId}.");

        return self::SUCCESS;
    }
}
