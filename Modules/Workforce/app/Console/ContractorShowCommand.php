<?php

namespace Modules\Workforce\Console;

use Illuminate\Console\Command;
use Modules\Workforce\Models\Contractor;
use Modules\Workforce\Models\ContractorAvailabilitySlot;
use Modules\Workforce\Models\ContractorRegionScope;
use Modules\Workforce\Models\ContractorSlotCommitment;
use Modules\Workforce\Services\ContractorAvailabilityService;

/**
 * Ops review: show one contractor's EM-02 picture (read-only) — registry status, the
 * region/scope coverage rows, the availability slots with their live remaining capacity
 * for the requested day, and the ACTIVE slot commitments, so dispatch can see why a
 * contractor is (or isn't) being offered work before touching anything.
 */
class ContractorShowCommand extends Command
{
    protected $signature = 'sophix:workforce:contractor-show
        {contractor : The contractor_id}
        {--date= : Calendar day (Y-m-d) to compute remaining capacity for (default: today)}';

    protected $description = 'Review: show a contractor\'s coverage, slots, live capacity and commitments (read-only)';

    public function handle(ContractorAvailabilityService $availability): int
    {
        $contractorId = (string) $this->argument('contractor');
        $contractor = Contractor::query()->where('contractor_id', $contractorId)->first();
        if (! $contractor) {
            $this->warn("No contractor {$contractorId}.");

            return self::SUCCESS;
        }

        $date = $this->option('date') ? \Illuminate\Support\Carbon::parse($this->option('date')) : now();

        $this->table(['Field', 'Value'], [
            ['contractor_id', $contractor->contractor_id],
            ['operator_code', $contractor->operator_code],
            ['code', $contractor->code],
            ['name', $contractor->name],
            ['type', $contractor->type],
            ['status', $contractor->status],
        ]);

        if ($contractor->status !== 'ACTIVE') {
            $this->warn("Contractor status {$contractor->status} — excluded from WO routing (R-EM-CS-1).");
        }

        $coverages = ContractorRegionScope::query()->where('contractor_id', $contractorId)->get();
        $this->info('Coverage (contractor_region_scope):');
        $this->table(['tech_region_id', 'service_scope', 'coverage_role', 'effective_from', 'effective_to'],
            $coverages->map(fn ($c) => [$c->tech_region_id, $c->service_scope, $c->coverage_role, (string) $c->effective_from, (string) ($c->effective_to ?? '—')])->all());

        $slots = ContractorAvailabilitySlot::query()->where('contractor_id', $contractorId)->get();
        $this->info("Availability slots (remaining capacity computed for {$date->toDateString()}):");
        $this->table(['slot_id', 'tech_region_id', 'scope', 'day_of_week', 'hours', 'max_concurrent', 'remaining', 'active'],
            $slots->map(fn ($s) => [
                $s->slot_id, $s->tech_region_id, $s->service_scope, $s->day_of_week,
                $s->hour_start.'–'.$s->hour_end, $s->max_concurrent,
                $availability->remainingCapacity($s, $date), $s->active ? 'yes' : 'no',
            ])->all());

        $active = ContractorSlotCommitment::query()->where('contractor_id', $contractorId)
            ->where('status', ContractorSlotCommitment::ACTIVE)->orderBy('committed_for_datetime')->get();
        $this->info('ACTIVE slot commitments:');
        $this->table(['commitment_id', 'slot_id', 'wo_id', 'committed_for', 'qty'],
            $active->map(fn ($c) => [$c->commitment_id, $c->slot_id, $c->wo_id, (string) $c->committed_for_datetime, $c->qty])->all());

        return self::SUCCESS;
    }
}
