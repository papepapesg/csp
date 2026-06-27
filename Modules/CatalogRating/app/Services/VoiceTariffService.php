<?php

namespace Modules\Catalog\Rating\Services;

use App\Foundation\Cache\SophixCache;
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
use Modules\Catalog\Support\CatalogCacheKeys;

/**
 * PLM-CFG-07 Voice Tariff Catalog admin + rating-lookup service. Pure
 * Laravel: CRUD + DRAFT→ACTIVE→RETIRED lifecycle, overlap validation, and a
 * cache-aside rating lookup (the read RAT-01 calls). It exposes catalog data and
 * validation only — it never rates a CDR itself (DD §2). Every emitted event
 * payload carries operatorCode so CatalogCacheInvalidator can evict the snapshot.
 */
class VoiceTariffService
{
    /** Binding scope precedence (DD §8.4 / §14): higher number wins. */
    private const SCOPE_RANK = [
        VoiceTariffBinding::SCOPE_SUBSCRIPTION_OVERRIDE => 3,
        VoiceTariffBinding::SCOPE_PACKAGE => 2,
        VoiceTariffBinding::SCOPE_SERVICE => 1,
    ];

    public function __construct(
        private readonly EventBus $events,
        private readonly SophixCache $cache,
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

    // -------------------------------------------------------- Rating lookup

    /**
     * The core read used by RAT-01 (DD §8.4). Resolves plan → zone → time band →
     * rate against a cached per-operator snapshot of the ACTIVE catalog, applying
     * the policy rules. Returns the §8.4 response shape plus resolutionStatus.
     *
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>
     */
    public function ratingLookup(array $req): array
    {
        $operator = $req['operatorCode'] ?? Context::operatorCode();
        $at = $this->ts($req['callStartedAt'] ?? null) ?? Carbon::now();
        $direction = $req['callDirection'] ?? 'OUTBOUND';
        $called = (string) ($req['calledNumberNormalized'] ?? '');

        $snapshot = $this->cache->remember(
            ...CatalogCacheKeys::voiceTariff($operator),
            ttlSeconds: CatalogCacheKeys::voiceTariffTtl(),
            source: fn () => $this->buildSnapshot($operator),
        );

        // (a) resolve tariff plan by binding precedence + effective at callStartedAt.
        $plan = $this->resolvePlan($snapshot, $req, $at);
        if ($plan === null) {
            return $this->quarantine('NO_PLAN');
        }

        // (b) longest-prefix match → zone.
        $zone = $this->resolveZone($snapshot, $called, $at);
        if ($zone === null) {
            return $this->quarantine('NO_ZONE');
        }

        // (c) pick the time band whose window contains callStartedAt (default ANYTIME).
        $band = $this->resolveTimeBand($snapshot, $at);

        // (d) find the rate for (plan, zone, time band, direction) active at callStartedAt.
        $rate = $this->resolveRate($snapshot, $plan['tariff_plan_id'], $zone['zone_id'], $band['time_band_id'] ?? null, $direction, $at);
        if ($rate === null) {
            return $this->quarantine('NO_RATE');
        }

        // Policy: EMERGENCY zone / ZERO_RATED → zero-rated, unitPrice 0 (R-VOICE-TAR-04/05).
        $zonePolicy = $zone['default_charge_policy'] ?? 'CHARGEABLE';
        $isZeroRated = $zone['zone_type'] === 'EMERGENCY'
            || $zonePolicy === 'ZERO_RATED'
            || $rate['charge_policy'] === 'ZERO_RATED';

        $chargePolicy = $isZeroRated ? 'ZERO_RATED' : $rate['charge_policy'];
        $unitPrice = $isZeroRated ? 0.0 : (float) $rate['unit_price'];

        return [
            'tariffPlanCode' => $plan['tariff_plan_code'],
            'zoneCode' => $zone['zone_code'],
            'timeBandCode' => $band['time_band_code'] ?? 'ANYTIME',
            'chargePolicy' => $chargePolicy,
            'unitType' => $rate['unit_type'],
            'unitPrice' => $unitPrice,
            'currency' => $plan['currency_code'],
            'initialIncrementSeconds' => (int) $rate['initial_increment_seconds'],
            'subsequentIncrementSeconds' => (int) $rate['subsequent_increment_seconds'],
            'setupFeeAmount' => (float) $rate['setup_fee_amount'],
            'minimumChargeAmount' => (float) $rate['minimum_charge_amount'],
            'taxableKind' => $rate['taxable_kind'],
            'taxableRef' => $rate['taxable_ref'],
            'resolutionStatus' => 'RESOLVED',
        ];
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
        $card = $this->ratingLookup($req);
        if (($card['resolutionStatus'] ?? null) !== 'RESOLVED') {
            return $card; // QUARANTINE / NO_RATE etc. — nothing to rate
        }

        $duration = max(0, (int) ($req['durationSeconds'] ?? 0));
        $remainingAllowance = max(0, (int) ($req['remainingAllowanceSeconds'] ?? 0));
        $policy = $card['chargePolicy'];

        if ($policy === 'QUARANTINE') {
            return $this->quarantine('RATE_QUARANTINE');
        }

        // Reservation + pulse rounding (Huawei CBS / Diameter terms).
        $billable = $this->reservePulse($duration, max(0, (int) $card['initialIncrementSeconds']), max(1, (int) $card['subsequentIncrementSeconds']));

        if ($policy === 'BLOCKED') {
            return $this->ratedResult($card, 'BLOCKED', 0, 0, 0, 0.0);
        }
        if ($policy === 'ZERO_RATED') {
            return $this->ratedResult($card, 'ZERO_RATED', $billable, 0, 0, 0.0); // free: no allowance burn, no charge
        }

        // Burn allowance first, charge the remaining seconds.
        $allowanceUsed = min($billable, $remainingAllowance);
        $chargeable = $billable - $allowanceUsed;

        $units = str_contains(strtoupper((string) $card['unitType']), 'SECOND') ? $chargeable : $chargeable / 60.0;
        $amount = $units * (float) $card['unitPrice'];
        if ($chargeable > 0) {
            $amount += (float) $card['setupFeeAmount'];
            $amount = max($amount, (float) $card['minimumChargeAmount']);
        }

        return $this->ratedResult($card, 'CHARGED', $billable, $allowanceUsed, $chargeable, round($amount, 2));
    }

    /** Round a duration up by the reservation (first block) then pulse (subsequent blocks). */
    private function reservePulse(int $duration, int $initial, int $pulse): int
    {
        if ($duration <= 0) {
            return 0;
        }
        if ($duration <= $initial) {
            return $initial;
        }

        return $initial + (int) (ceil(($duration - $initial) / $pulse) * $pulse);
    }

    /**
     * @param  array<string,mixed>  $card
     * @return array<string,mixed>
     */
    private function ratedResult(array $card, string $chargeStatus, int $billable, int $allowanceUsed, int $chargeable, float $amount): array
    {
        return [
            'resolutionStatus' => 'RATED',
            'chargeStatus' => $chargeStatus,                 // CHARGED | ZERO_RATED | BLOCKED
            'tariffPlanCode' => $card['tariffPlanCode'] ?? null,
            'zoneCode' => $card['zoneCode'] ?? null,
            'timeBandCode' => $card['timeBandCode'] ?? null,
            'billableSeconds' => $billable,
            'allowanceConsumedSeconds' => $allowanceUsed,
            'chargeableSeconds' => $chargeable,
            'amount' => $amount,
            'currency' => $card['currency'] ?? null,
            'taxableKind' => $card['taxableKind'] ?? null,
            'taxableRef' => $card['taxableRef'] ?? null,
        ];
    }

    /** @return array{resolutionStatus:string,reason:string} */
    private function quarantine(string $reason): array
    {
        // R-VOICE-TAR-07: do not silently zero-rate; quarantine the CDR.
        return ['resolutionStatus' => 'QUARANTINE', 'reason' => $reason];
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
        return [
            'plans' => VoiceTariffPlan::query()
                ->where('operator_code', $operator)->where('status', VoiceTariffPlan::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
            'zones' => VoiceDestinationZone::query()
                ->where('operator_code', $operator)->where('status', VoiceDestinationZone::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
            'prefixes' => VoiceDestinationPrefix::query()
                ->where('operator_code', $operator)->where('status', VoiceDestinationPrefix::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
            'timeBands' => VoiceTimeBand::query()
                ->where('operator_code', $operator)->where('status', VoiceTimeBand::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
            'rates' => VoiceTariffRate::query()
                ->where('operator_code', $operator)->where('status', VoiceTariffRate::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
            'bindings' => VoiceTariffBinding::query()
                ->where('operator_code', $operator)->where('status', VoiceTariffBinding::STATUS_ACTIVE)
                ->get()->map->getAttributes()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $req
     * @return array<string,mixed>|null
     */
    private function resolvePlan(array $snapshot, array $req, Carbon $at): ?array
    {
        $refByScope = [
            VoiceTariffBinding::SCOPE_SUBSCRIPTION_OVERRIDE => $req['subscriptionId'] ?? null,
            VoiceTariffBinding::SCOPE_PACKAGE => $req['packageRef'] ?? null,
            VoiceTariffBinding::SCOPE_SERVICE => $req['serviceRef'] ?? null,
        ];

        $best = null;
        $bestRank = -1;
        $bestPriority = PHP_INT_MIN;

        foreach ($snapshot['bindings'] as $b) {
            $scope = $b['binding_scope'];
            $expectedRef = $refByScope[$scope] ?? null;
            if ($expectedRef === null || (string) $b['binding_ref'] !== (string) $expectedRef) {
                continue;
            }
            if (! $this->effective($b['effective_from'] ?? null, $b['effective_to'] ?? null, $at)) {
                continue;
            }
            $rank = self::SCOPE_RANK[$scope] ?? 0;
            $priority = (int) ($b['priority'] ?? 0);
            // Higher scope precedence wins; tie-break by higher priority.
            if ($rank > $bestRank || ($rank === $bestRank && $priority > $bestPriority)) {
                $bestRank = $rank;
                $bestPriority = $priority;
                $best = $b;
            }
        }

        if ($best === null) {
            return null;
        }

        foreach ($snapshot['plans'] as $p) {
            if ($p['tariff_plan_id'] === $best['tariff_plan_id']
                && $this->effective($p['effective_from'] ?? null, $p['effective_to'] ?? null, $at)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * R-VOICE-TAR-03: longest-prefix match, tie-broken by match_priority.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    private function resolveZone(array $snapshot, string $called, Carbon $at): ?array
    {
        $bestPrefix = null;
        $bestLen = -1;
        $bestPriority = PHP_INT_MIN;

        foreach ($snapshot['prefixes'] as $px) {
            $value = (string) $px['prefix'];
            if ($value === '' || ! str_starts_with($called, $value)) {
                continue;
            }
            if (! $this->effective($px['effective_from'] ?? null, $px['effective_to'] ?? null, $at)) {
                continue;
            }
            $len = strlen($value);
            $priority = (int) ($px['match_priority'] ?? 0);
            if ($len > $bestLen || ($len === $bestLen && $priority > $bestPriority)) {
                $bestLen = $len;
                $bestPriority = $priority;
                $bestPrefix = $px;
            }
        }

        if ($bestPrefix === null) {
            return null;
        }

        foreach ($snapshot['zones'] as $z) {
            if ($z['zone_id'] === $bestPrefix['zone_id']) {
                return $z;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    private function resolveTimeBand(array $snapshot, Carbon $at): ?array
    {
        $anytime = null;
        foreach ($snapshot['timeBands'] as $tb) {
            if (($tb['time_band_code'] ?? null) === 'ANYTIME') {
                $anytime = $tb;
            }
            if ($this->bandContains($tb, $at)) {
                return $tb;
            }
        }

        return $anytime;
    }

    /** @param array<string,mixed> $tb */
    private function bandContains(array $tb, Carbon $at): bool
    {
        $tz = $tb['timezone'] ?? 'UTC';
        $local = $at->copy()->setTimezone($tz);

        $days = array_filter(array_map('trim', explode(',', (string) ($tb['days_of_week'] ?? ''))));
        if ($days !== []) {
            $dow = strtoupper(substr($local->format('D'), 0, 3));
            if (! in_array($dow, array_map('strtoupper', $days), true)) {
                return false;
            }
        }

        $start = (string) ($tb['start_time_local'] ?? '00:00:00');
        $end = (string) ($tb['end_time_local'] ?? '23:59:59');
        $now = $local->format('H:i:s');

        return $now >= $start && $now <= $end;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    private function resolveRate(array $snapshot, string $planId, string $zoneId, ?string $bandId, string $direction, Carbon $at): ?array
    {
        $best = null;
        $bestFrom = null;

        foreach ($snapshot['rates'] as $r) {
            if ($r['tariff_plan_id'] !== $planId || $r['zone_id'] !== $zoneId || $r['call_direction'] !== $direction) {
                continue;
            }
            if ($bandId !== null && $r['time_band_id'] !== $bandId) {
                continue;
            }
            if (! $this->effective($r['effective_from'] ?? null, $r['effective_to'] ?? null, $at)) {
                continue;
            }
            // R-VOICE-TAR-02: pick the most recent effective_from active at call time.
            $from = $this->ts($r['effective_from'] ?? null);
            if ($best === null || ($from !== null && ($bestFrom === null || $from->greaterThan($bestFrom)))) {
                $best = $r;
                $bestFrom = $from;
            }
        }

        return $best;
    }

    // -------------------------------------------------------------- helpers

    private function effective(mixed $from, mixed $to, Carbon $at): bool
    {
        $f = $this->ts($from);
        $t = $this->ts($to);
        if ($f !== null && $at->lessThan($f)) {
            return false;
        }
        if ($t !== null && $at->greaterThan($t)) {
            return false;
        }

        return true;
    }

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
