<?php

namespace Modules\Workforce\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Workforce\Models\Contractor;
use Modules\Workforce\Models\ContractorAvailabilitySlot;
use Modules\Workforce\Models\ContractorSlotCommitment;

/**
 * EM-02 §5.1/5.2 — the WO module's hot path. Given a region + scope + required skills +
 * datetime window, return the ranked list of contractors with spare slot capacity, and
 * let the caller atomically commit a WO against a slot. EM-02 owns the capacity facts;
 * the *selection* policy (which contractor to pick) is the WO module's `assign-contractor`
 * Drools rule wrapping this endpoint.
 */
class ContractorAvailabilityService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * R-EM-CS-1..6: resolve contractors with capacity for the request.
     *
     * @param  array<int,string>  $requiredSkills
     * @return array{availableContractors:array<int,array<string,mixed>>, totalAvailable:int}
     */
    public function resolve(string $techRegionId, string $serviceScope, array $requiredSkills, CarbonInterface $windowStart, CarbonInterface $windowEnd, int $concurrentDemand = 1, bool $preferEmergency = false): array
    {
        $operator = Context::operatorCode();
        $today = now()->toDateString();

        // Step 1: contractors covering (region, scope) on an active, in-effect coverage row.
        $coverages = DB::table('contractor_region_scope')
            ->where('operator_code', $operator)
            ->where('tech_region_id', $techRegionId)->where('service_scope', $serviceScope)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->get()->keyBy('contractor_id');

        $results = [];
        foreach ($coverages as $contractorId => $coverage) {
            // R-EM-CS-1: deactivated contractors are excluded.
            $contractor = Contractor::query()->where('contractor_id', $contractorId)->first();
            if (! $contractor || ($contractor->status ?? 'ACTIVE') === 'INACTIVE' || ($contractor->active ?? true) === false) {
                continue;
            }
            // R-EM-CS-3: contractor must hold ALL required skills in this region (hard filter).
            $matched = DB::table('contractor_region_skill')
                ->where('operator_code', $operator)->where('contractor_id', $contractorId)
                ->where('tech_region_id', $techRegionId)->where('active', true)
                ->whereIn('skill_code', $requiredSkills ?: ['__none__'])->pluck('skill_code')->all();
            if (count(array_unique($matched)) < count(array_unique($requiredSkills))) {
                continue;
            }

            // R-EM-CS-4: find an eligible slot for the window; R-EM-CS-5: with capacity.
            $slot = $this->bestEligibleSlot($contractorId, $techRegionId, $serviceScope, $windowStart, $windowEnd, $concurrentDemand, $preferEmergency);
            if (! $slot) {
                continue;
            }

            $results[] = [
                'contractorId' => $contractorId,
                'contractorType' => $contractor->type ?? null,
                'coverageRole' => $coverage->coverage_role,
                'matchedSkills' => array_values(array_unique($matched)),
                'slot' => [
                    'slotId' => $slot['slot']->slot_id,
                    'dayOfWeek' => $slot['slot']->day_of_week,
                    'maxConcurrent' => $slot['slot']->max_concurrent,
                    'remainingCapacity' => $slot['remaining'],
                ],
                '_rankRole' => $coverage->coverage_role === 'PRIMARY' || $coverage->coverage_role === 'EXCLUSIVE' ? 0 : 1,
                '_rankRatio' => $slot['slot']->max_concurrent > 0 ? $slot['remaining'] / $slot['slot']->max_concurrent : 0,
            ];
        }

        // Step 7: rank by coverage_role (PRIMARY first), then remaining-capacity ratio.
        usort($results, fn ($a, $b) => [$a['_rankRole'], -$a['_rankRatio']] <=> [$b['_rankRole'], -$b['_rankRatio']]);
        $rank = 1;
        foreach ($results as &$r) {
            $r['rank'] = $rank++;
            $r['rankRationale'] = ($r['coverageRole'] === 'PRIMARY' ? 'PRIMARY coverage' : 'BACKUP coverage').' + skill match + capacity';
            unset($r['_rankRole'], $r['_rankRatio']);
        }

        return ['availableContractors' => $results, 'totalAvailable' => count($results)];
    }

    /**
     * R-EM-CS-4 slot eligibility + R-EM-CS-5 capacity, returning the slot with the most
     * remaining capacity for the requested window.
     *
     * @return array{slot:ContractorAvailabilitySlot, remaining:int}|null
     */
    private function bestEligibleSlot(string $contractorId, string $techRegionId, string $serviceScope, CarbonInterface $windowStart, CarbonInterface $windowEnd, int $concurrentDemand, bool $preferEmergency): ?array
    {
        $today = now()->toDateString();
        $dow = strtoupper($windowStart->format('l')); // MONDAY..SUNDAY

        $slots = ContractorAvailabilitySlot::query()
            ->where('contractor_id', $contractorId)->where('tech_region_id', $techRegionId)
            ->where('service_scope', $serviceScope)->where('active', true)
            ->whereIn('day_of_week', [$dow, 'ALL_WEEK'])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $today))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->get();

        $best = null;
        foreach ($slots as $slot) {
            // hour window: the requested [start,end] must sit inside the slot's [hour_start,hour_end].
            $tz = $slot->timezone ?: 'Africa/Nairobi';
            $reqStart = $windowStart->copy()->setTimezone($tz)->format('H:i:s');
            $reqEnd = $windowEnd->copy()->setTimezone($tz)->format('H:i:s');
            if ($reqStart < $slot->hour_start || $reqEnd > $slot->hour_end) {
                continue;
            }
            // emergency-only slots only serve emergency WOs.
            if ($slot->emergency_only && ! $preferEmergency) {
                continue;
            }
            $remaining = $this->remainingCapacity($slot, $windowStart);
            if ($remaining < $concurrentDemand) {
                continue;
            }
            if ($best === null || $remaining > $best['remaining']) {
                $best = ['slot' => $slot, 'remaining' => $remaining];
            }
        }

        return $best;
    }

    /** R-EM-CS-5: remaining = max_concurrent − sum(qty of ACTIVE commitments on the same calendar day). */
    public function remainingCapacity(ContractorAvailabilitySlot $slot, CarbonInterface $datetime): int
    {
        $committed = (int) ContractorSlotCommitment::query()
            ->where('slot_id', $slot->slot_id)->where('status', ContractorSlotCommitment::ACTIVE)
            ->whereDate('committed_for_datetime', $datetime->toDateString())
            ->sum('qty');

        return max($slot->max_concurrent - $committed, 0);
    }

    /**
     * R-EM-CS-6: atomically commit a WO against a slot, or reject INSUFFICIENT_CAPACITY.
     */
    public function commit(string $slotId, string $woId, CarbonInterface $committedForDatetime, int $qty = 1): ContractorSlotCommitment
    {
        return DB::transaction(function () use ($slotId, $woId, $committedForDatetime, $qty) {
            $slot = ContractorAvailabilitySlot::query()->where('slot_id', $slotId)->lockForUpdate()->first();
            if (! $slot) {
                throw DomainException::ruleRejected('SLOT_NOT_FOUND', "Availability slot {$slotId} not found.");
            }
            $remaining = $this->remainingCapacity($slot, $committedForDatetime);
            if ($remaining < $qty) {
                throw new DomainException('INSUFFICIENT_CAPACITY', "Slot at capacity ({$slot->max_concurrent} committed) for {$committedForDatetime->toDateString()}.", 409);
            }

            $commitment = ContractorSlotCommitment::query()->create([
                'commitment_id' => Id::make('cmt'),
                'operator_code' => $slot->operator_code,
                'slot_id' => $slot->slot_id,
                'contractor_id' => $slot->contractor_id,
                'wo_id' => $woId,
                'committed_for_datetime' => $committedForDatetime,
                'qty' => $qty,
                'status' => ContractorSlotCommitment::ACTIVE,
            ]);
            $this->emit('ContractorSlotCommitmentCreated', $commitment);

            return $commitment;
        });
    }

    /** R-EM-CS-7: WO completed → CONSUMED (capacity stays consumed). */
    public function consume(ContractorSlotCommitment $commitment): ContractorSlotCommitment
    {
        $commitment->update(['status' => ContractorSlotCommitment::CONSUMED, 'consumed_at' => now()]);
        $this->emit('ContractorSlotCommitmentConsumed', $commitment);

        return $commitment;
    }

    /** R-EM-CS-7: WO cancelled → RELEASED (capacity restored if the day hasn't passed). */
    public function release(ContractorSlotCommitment $commitment): ContractorSlotCommitment
    {
        $commitment->update(['status' => ContractorSlotCommitment::RELEASED, 'released_at' => now()]);
        $this->emit('ContractorSlotCommitmentReleased', $commitment);

        return $commitment;
    }

    /** WO finalized → CONSUME every ACTIVE commitment for that work order. @return int count */
    public function consumeForWorkOrder(string $woId): int
    {
        return $this->resolveForWorkOrder($woId, fn ($c) => $this->consume($c));
    }

    /** WO cancelled → RELEASE every ACTIVE commitment for that work order (restore capacity). */
    public function releaseForWorkOrder(string $woId): int
    {
        return $this->resolveForWorkOrder($woId, fn ($c) => $this->release($c));
    }

    /** @param callable(ContractorSlotCommitment):mixed $apply */
    private function resolveForWorkOrder(string $woId, callable $apply): int
    {
        $n = 0;
        ContractorSlotCommitment::query()->where('wo_id', $woId)->where('status', ContractorSlotCommitment::ACTIVE)
            ->get()->each(function ($c) use ($apply, &$n) {
                $apply($c);
                $n++;
            });

        return $n;
    }

    private function emit(string $type, ContractorSlotCommitment $commitment): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: 'em.cs',
            payload: ['commitmentId' => $commitment->commitment_id, 'slotId' => $commitment->slot_id, 'contractorId' => $commitment->contractor_id, 'woId' => $commitment->wo_id, 'status' => $commitment->status],
            aggregateType: 'ContractorSlotCommitment',
            aggregateId: $commitment->commitment_id,
        ));
    }
}
