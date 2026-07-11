<?php

namespace Modules\Catalog\Rating\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Rating\Models\VoiceDestinationPrefix;
use Modules\Catalog\Rating\Models\VoiceDestinationZone;
use Modules\Catalog\Rating\Models\VoiceTariffAllowance;
use Modules\Catalog\Rating\Models\VoiceTariffBinding;
use Modules\Catalog\Rating\Models\VoiceTariffPlan;
use Modules\Catalog\Rating\Models\VoiceTariffRate;
use Modules\Catalog\Rating\Models\VoiceTimeBand;

/**
 * PLM-CFG-07 Voice Tariff Catalog admin + rating-lookup service. Pure
 * Laravel: CRUD + DRAFT→ACTIVE→RETIRED lifecycle, overlap validation, and a
 * cache-aside rating lookup (the read RAT-01 calls). It exposes catalog data and
 * validation only — it never rates a CDR itself (DD §2). Every emitted event
 * payload carries operatorCode so CatalogCacheInvalidator can evict the snapshot.
 */
class VoiceTariffService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly VoiceTariffResolver $resolver,
        private readonly VoiceCallRater $rater,
    ) {}

    // ----------------------------------------------------------------- Plans

    /** @param array<string,mixed> $data */
    public function createPlan(array $data): VoiceTariffPlan
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $this->assertUnique(VoiceTariffPlan::query(), $operator, 'tariff_plan_code', $data['tariff_plan_code'] ?? null, 'tariff plan code');

        return DB::transaction(function () use ($data, $operator) {
            $plan = VoiceTariffPlan::query()->create($data + [
                'operator_code' => $operator,
                'status' => VoiceTariffPlan::STATUS_DRAFT,
            ]);
            $this->emitPlan(CatalogEvents::VOICE_TARIFF_PLAN_CREATED, $plan);

            return $plan;
        });
    }

    /** @param array<string,mixed> $data */
    public function updatePlan(VoiceTariffPlan $plan, array $data): VoiceTariffPlan
    {
        unset($data['operator_code'], $data['tariff_plan_code'], $data['status']);

        return DB::transaction(function () use ($plan, $data) {
            $plan->update($data);

            return $plan->refresh();
        });
    }

    /** DRAFT → ACTIVE (emit VoiceTariffPlanActivated; invalidates the tariff snapshot). */
    public function activatePlan(VoiceTariffPlan $plan): VoiceTariffPlan
    {
        if ($plan->status !== VoiceTariffPlan::STATUS_DRAFT) {
            throw new DomainException('CONFLICT', 'Only a DRAFT tariff plan can be activated.', 409);
        }

        return DB::transaction(function () use ($plan) {
            $plan->update(['status' => VoiceTariffPlan::STATUS_ACTIVE]);
            $this->emitPlan(CatalogEvents::VOICE_TARIFF_PLAN_ACTIVATED, $plan);

            return $plan->refresh();
        });
    }

    /** → RETIRED (emit VoiceTariffPlanRetired). */
    public function retirePlan(VoiceTariffPlan $plan): VoiceTariffPlan
    {
        if ($plan->status === VoiceTariffPlan::STATUS_RETIRED) {
            throw new DomainException('CONFLICT', 'Tariff plan is already retired.', 409);
        }

        return DB::transaction(function () use ($plan) {
            $plan->update(['status' => VoiceTariffPlan::STATUS_RETIRED]);
            $this->emitPlan(CatalogEvents::VOICE_TARIFF_PLAN_RETIRED, $plan);

            return $plan->refresh();
        });
    }

    // ------------------------------------------------------- Zones / bands

    /** @param array<string,mixed> $data */
    public function createZone(array $data): VoiceDestinationZone
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $this->assertUnique(VoiceDestinationZone::query(), $operator, 'zone_code', $data['zone_code'] ?? null, 'zone code');

        return VoiceDestinationZone::query()->create($data + [
            'operator_code' => $operator,
            'status' => VoiceDestinationZone::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function createTimeBand(array $data): VoiceTimeBand
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $this->assertUnique(VoiceTimeBand::query(), $operator, 'time_band_code', $data['time_band_code'] ?? null, 'time band code');

        return VoiceTimeBand::query()->create($data + [
            'operator_code' => $operator,
            'status' => VoiceTimeBand::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------------- Prefixes

    /** @param array<string,mixed> $data */
    public function createPrefix(array $data): VoiceDestinationPrefix
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $this->assertNoDuplicateActivePrefix($operator, (string) ($data['prefix'] ?? ''));

        return DB::transaction(function () use ($data, $operator) {
            $prefix = VoiceDestinationPrefix::query()->create($data + [
                'operator_code' => $operator,
                'status' => VoiceDestinationPrefix::STATUS_ACTIVE,
            ]);
            $this->emitPrefixChanged($operator, $prefix->prefix_id);

            return $prefix;
        });
    }

    /**
     * Bulk import prefixes. Rejects the whole batch if any row duplicates an
     * existing ACTIVE prefix for the operator, or duplicates another row in the
     * same batch (R-VOICE-TAR-03 write-side guard; longest-prefix is a read concern).
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,VoiceDestinationPrefix>
     */
    public function bulkImportPrefixes(array $rows): array
    {
        $operator = Context::operatorCode();
        $seen = [];
        foreach ($rows as $i => $row) {
            $p = (string) ($row['prefix'] ?? '');
            if (isset($seen[$p])) {
                throw new DomainException('R-VOICE-TAR-03', "Duplicate prefix {$p} within the import batch.", 422);
            }
            $seen[$p] = true;
            $this->assertNoDuplicateActivePrefix($operator, $p);
        }

        return DB::transaction(function () use ($rows, $operator) {
            $created = [];
            foreach ($rows as $row) {
                $created[] = VoiceDestinationPrefix::query()->create($row + [
                    'operator_code' => $operator,
                    'status' => VoiceDestinationPrefix::STATUS_ACTIVE,
                ]);
            }
            $this->emitPrefixChanged($operator, null);

            return $created;
        });
    }

    // ---------------------------------------------------------------- Rates

    /** @param array<string,mixed> $data */
    public function createRate(array $data): VoiceTariffRate
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $data['operator_code'] = $operator;

        // R-VOICE-TAR-08: a new ACTIVE rate must not overlap an existing one.
        $conflicts = $this->validateOverlap([$data]);
        if ($conflicts !== []) {
            throw new DomainException('R-VOICE-TAR-08', 'Rate overlaps an existing rate for the same plan/zone/time band/direction/period.', 422);
        }

        return DB::transaction(function () use ($data, $operator) {
            $rate = VoiceTariffRate::query()->create($data + ['status' => VoiceTariffRate::STATUS_ACTIVE]);
            $this->emitRateChanged($operator, $rate->rate_id);

            return $rate;
        });
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,VoiceTariffRate>
     */
    public function bulkImportRates(array $rows): array
    {
        $operator = Context::operatorCode();
        foreach ($rows as &$row) {
            $row['operator_code'] = $row['operator_code'] ?? $operator;
        }
        unset($row);

        // Reject if the batch overlaps itself or existing rates (R-VOICE-TAR-08).
        $conflicts = $this->validateOverlap($rows);
        if ($conflicts !== []) {
            throw new DomainException('R-VOICE-TAR-08', 'One or more imported rates overlap existing or sibling rates.', 422, false, [], null);
        }

        return DB::transaction(function () use ($rows, $operator) {
            $created = [];
            foreach ($rows as $row) {
                $created[] = VoiceTariffRate::query()->create($row + ['status' => VoiceTariffRate::STATUS_ACTIVE]);
            }
            $this->emitRateChanged($operator, null);

            return $created;
        });
    }

    /**
     * R-VOICE-TAR-08: rates cannot overlap for the same (plan, zone, time band,
     * call direction, effective period). Validates the candidate set against each
     * other AND against persisted ACTIVE rates. Returns the list of conflicts.
     *
     * @param  array<int,array<string,mixed>>  $candidates
     * @return array<int,array{candidateIndex:int,conflictWith:string,reason:string}>
     */
    public function validateOverlap(array $candidates): array
    {
        $operator = Context::operatorCode();
        $conflicts = [];

        foreach ($candidates as $i => $c) {
            $op = $c['operator_code'] ?? $operator;
            $planId = $c['tariff_plan_id'] ?? null;
            $zoneId = $c['zone_id'] ?? null;
            $bandId = $c['time_band_id'] ?? null;
            $direction = $c['call_direction'] ?? null;
            $from = $this->ts($c['effective_from'] ?? null);
            $to = $this->ts($c['effective_to'] ?? null);

            // Persisted ACTIVE rates with the same key.
            $existing = VoiceTariffRate::query()
                ->where('operator_code', $op)
                ->where('tariff_plan_id', $planId)
                ->where('zone_id', $zoneId)
                ->where('time_band_id', $bandId)
                ->where('call_direction', $direction)
                ->where('status', VoiceTariffRate::STATUS_ACTIVE)
                ->get();

            foreach ($existing as $row) {
                if (isset($c['rate_id']) && $row->rate_id === $c['rate_id']) {
                    continue;
                }
                if ($this->periodsOverlap($from, $to, $this->ts($row->effective_from), $this->ts($row->effective_to))) {
                    $conflicts[] = ['candidateIndex' => $i, 'conflictWith' => $row->rate_id, 'reason' => 'EFFECTIVE_PERIOD_OVERLAP'];
                }
            }

            // Other candidates in the same batch with the same key.
            foreach ($candidates as $j => $other) {
                if ($j <= $i) {
                    continue;
                }
                if (($other['tariff_plan_id'] ?? null) === $planId
                    && ($other['zone_id'] ?? null) === $zoneId
                    && ($other['time_band_id'] ?? null) === $bandId
                    && ($other['call_direction'] ?? null) === $direction
                    && (($other['operator_code'] ?? $operator) === $op)
                    && $this->periodsOverlap($from, $to, $this->ts($other['effective_from'] ?? null), $this->ts($other['effective_to'] ?? null))
                ) {
                    $conflicts[] = ['candidateIndex' => $i, 'conflictWith' => "candidate#{$j}", 'reason' => 'EFFECTIVE_PERIOD_OVERLAP'];
                }
            }
        }

        return $conflicts;
    }

    // ------------------------------------------------- Allowances / bindings

    /** @param array<string,mixed> $data */
    public function createAllowance(array $data): VoiceTariffAllowance
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $dupe = VoiceTariffAllowance::query()
            ->where('operator_code', $operator)
            ->where('tariff_plan_id', $data['tariff_plan_id'] ?? null)
            ->where('allowance_code', $data['allowance_code'] ?? null)
            ->exists();
        if ($dupe) {
            throw new DomainException('R-VOICE-TAR-10', 'Allowance code already exists for this plan.', 422);
        }

        return VoiceTariffAllowance::query()->create($data + [
            'operator_code' => $operator,
            'status' => VoiceTariffAllowance::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed> $data */
    public function createBinding(array $data): VoiceTariffBinding
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();

        return DB::transaction(function () use ($data, $operator) {
            $binding = VoiceTariffBinding::query()->create($data + [
                'operator_code' => $operator,
                'status' => VoiceTariffBinding::STATUS_ACTIVE,
            ]);
            $this->emitBindingChanged($operator, $binding->binding_id);

            return $binding;
        });
    }

    /** Compatibility façade for callers that still use the catalog write service. */
    public function ratingLookup(array $req): array
    {
        return $this->resolver->resolve($req);
    }

    /**
     * Rate a single voice CDR: resolve the rate card (ratingLookup), apply the
     * RESERVATION (initial increment) + PULSE (subsequent increment) rounding to the
     * call duration, burn the supplied allowance seconds first, then charge the
     * remainder (unit price + setup fee, floored at the minimum charge). Stateless —
     * the caller (mediation/Billing) owns the allowance balance + carry-over; this
     * returns how many seconds to charge, how much allowance was consumed, and the
     * amount. Closes the gap where the rich rate fields were modelled but unused.
     *
     * @param  array<string,mixed>  $req  ratingLookup inputs + durationSeconds, remainingAllowanceSeconds?
     * @return array<string,mixed>
     */
    public function rateCall(array $req): array
    {
        return $this->rater->rate($req);
    }

    // ---------------------------------------------------- snapshot + resolve

    /**
     * Build a plain-array snapshot of the operator's ACTIVE voice catalog. Plain
     * arrays so it serializes cleanly into the cache and a rate/prefix/binding/plan
     * change event (which CatalogCacheInvalidator evicts) forces a rebuild.
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function buildSnapshot(string $operator): array
    {
        return $this->resolver->buildSnapshot($operator);
    }

    // -------------------------------------------------------------- helpers

    private function periodsOverlap(?Carbon $aFrom, ?Carbon $aTo, ?Carbon $bFrom, ?Carbon $bTo): bool
    {
        // Treat a null `from` as -infinity and a null `to` as +infinity. Two periods
        // overlap unless one ends before the other begins.
        if ($aTo !== null && $bFrom !== null && $aTo->lessThan($bFrom)) {
            return false;
        }
        if ($bTo !== null && $aFrom !== null && $bTo->lessThan($aFrom)) {
            return false;
        }

        return true;
    }

    private function ts(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse($value);
    }

    private function assertUnique($query, string $operator, string $column, ?string $value, string $label): void
    {
        if ($value === null) {
            return;
        }
        if ($query->where('operator_code', $operator)->where($column, $value)->exists()) {
            throw new DomainException('R-VOICE-TAR-01', ucfirst($label)." {$value} already exists for this operator.", 422);
        }
    }

    private function assertNoDuplicateActivePrefix(string $operator, string $prefix): void
    {
        if ($prefix === '') {
            return;
        }
        $dupe = VoiceDestinationPrefix::query()
            ->where('operator_code', $operator)
            ->where('prefix', $prefix)
            ->where('status', VoiceDestinationPrefix::STATUS_ACTIVE)
            ->exists();
        if ($dupe) {
            throw new DomainException('R-VOICE-TAR-03', "Active prefix {$prefix} already exists for this operator.", 422);
        }
    }

    // --------------------------------------------------------------- events

    private function emitPlan(string $type, VoiceTariffPlan $plan): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: [
                'operatorCode' => $plan->operator_code,
                'tariffPlanId' => $plan->tariff_plan_id,
                'tariffPlanCode' => $plan->tariff_plan_code,
                'status' => $plan->status,
            ],
            aggregateType: 'VoiceTariffPlan',
            aggregateId: $plan->tariff_plan_id,
        ));
    }

    private function emitPrefixChanged(string $operator, ?string $prefixId): void
    {
        $this->events->publish(new DomainEvent(
            type: CatalogEvents::VOICE_DESTINATION_PREFIX_CHANGED,
            topic: CatalogEvents::TOPIC,
            payload: ['operatorCode' => $operator, 'prefixId' => $prefixId],
            aggregateType: 'VoiceDestinationPrefix',
            aggregateId: $prefixId,
        ));
    }

    private function emitRateChanged(string $operator, ?string $rateId): void
    {
        $this->events->publish(new DomainEvent(
            type: CatalogEvents::VOICE_TARIFF_RATE_CHANGED,
            topic: CatalogEvents::TOPIC,
            payload: ['operatorCode' => $operator, 'rateId' => $rateId],
            aggregateType: 'VoiceTariffRate',
            aggregateId: $rateId,
        ));
    }

    private function emitBindingChanged(string $operator, ?string $bindingId): void
    {
        $this->events->publish(new DomainEvent(
            type: CatalogEvents::VOICE_TARIFF_BINDING_CHANGED,
            topic: CatalogEvents::TOPIC,
            payload: ['operatorCode' => $operator, 'bindingId' => $bindingId],
            aggregateType: 'VoiceTariffBinding',
            aggregateId: $bindingId,
        ));
    }
}
