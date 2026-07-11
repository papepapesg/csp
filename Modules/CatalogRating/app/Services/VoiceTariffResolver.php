<?php

namespace Modules\Catalog\Rating\Services;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Support\Context;
use Illuminate\Support\Carbon;
use Modules\Catalog\Rating\Models\VoiceDestinationPrefix;
use Modules\Catalog\Rating\Models\VoiceDestinationZone;
use Modules\Catalog\Rating\Models\VoiceTariffBinding;
use Modules\Catalog\Rating\Models\VoiceTariffPlan;
use Modules\Catalog\Rating\Models\VoiceTariffRate;
use Modules\Catalog\Rating\Models\VoiceTimeBand;
use Modules\Catalog\Support\CatalogCacheKeys;

/** Resolves an immutable, cached view of the executable voice tariff catalog. */
class VoiceTariffResolver
{
    private const SCOPE_RANK = [
        VoiceTariffBinding::SCOPE_SUBSCRIPTION_OVERRIDE => 3,
        VoiceTariffBinding::SCOPE_PACKAGE => 2,
        VoiceTariffBinding::SCOPE_SERVICE => 1,
    ];

    public function __construct(private readonly SophixCache $cache) {}

    /** @param array<string,mixed> $request */
    public function resolve(array $request): array
    {
        $operator = $request['operatorCode'] ?? Context::operatorCode();
        $at = $this->timestamp($request['callStartedAt'] ?? null) ?? Carbon::now();
        $direction = $request['callDirection'] ?? 'OUTBOUND';
        $called = (string) ($request['calledNumberNormalized'] ?? '');

        [$sourceModule, $aggregate, $catalogId] = CatalogCacheKeys::voiceTariff($operator);
        $snapshot = $this->cache->remember(
            $sourceModule,
            $aggregate,
            $catalogId,
            CatalogCacheKeys::voiceTariffTtl(),
            fn () => $this->buildSnapshot($operator),
        );

        $plan = $this->resolvePlan($snapshot, $request, $at);
        if ($plan === null) {
            return $this->quarantine('NO_PLAN');
        }

        $zone = $this->resolveZone($snapshot, $called, $at);
        if ($zone === null) {
            return $this->quarantine('NO_ZONE');
        }

        $band = $this->resolveTimeBand($snapshot, $at);
        $rate = $this->resolveRate(
            $snapshot,
            $plan['tariff_plan_id'],
            $zone['zone_id'],
            $band['time_band_id'] ?? null,
            $direction,
            $at,
        );
        if ($rate === null) {
            return $this->quarantine('NO_RATE');
        }

        $zonePolicy = $zone['default_charge_policy'] ?? 'CHARGEABLE';
        $zeroRated = $zone['zone_type'] === 'EMERGENCY'
            || $zonePolicy === 'ZERO_RATED'
            || $rate['charge_policy'] === 'ZERO_RATED';

        return [
            'tariffPlanCode' => $plan['tariff_plan_code'],
            'zoneCode' => $zone['zone_code'],
            'timeBandCode' => $band['time_band_code'] ?? 'ANYTIME',
            'chargePolicy' => $zeroRated ? 'ZERO_RATED' : $rate['charge_policy'],
            'unitType' => $rate['unit_type'],
            'unitPrice' => $zeroRated ? 0.0 : (float) $rate['unit_price'],
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

    /** @return array<string,array<int,array<string,mixed>>> */
    public function buildSnapshot(string $operator): array
    {
        return [
            'plans' => $this->activeRows(VoiceTariffPlan::query(), $operator, VoiceTariffPlan::STATUS_ACTIVE),
            'zones' => $this->activeRows(VoiceDestinationZone::query(), $operator, VoiceDestinationZone::STATUS_ACTIVE),
            'prefixes' => $this->activeRows(VoiceDestinationPrefix::query(), $operator, VoiceDestinationPrefix::STATUS_ACTIVE),
            'timeBands' => $this->activeRows(VoiceTimeBand::query(), $operator, VoiceTimeBand::STATUS_ACTIVE),
            'rates' => $this->activeRows(VoiceTariffRate::query(), $operator, VoiceTariffRate::STATUS_ACTIVE),
            'bindings' => $this->activeRows(VoiceTariffBinding::query(), $operator, VoiceTariffBinding::STATUS_ACTIVE),
        ];
    }

    private function activeRows($query, string $operator, string $status): array
    {
        return $query->where('operator_code', $operator)
            ->where('status', $status)
            ->get()
            ->map->getAttributes()
            ->all();
    }

    private function resolvePlan(array $snapshot, array $request, Carbon $at): ?array
    {
        $references = [
            VoiceTariffBinding::SCOPE_SUBSCRIPTION_OVERRIDE => $request['subscriptionId'] ?? null,
            VoiceTariffBinding::SCOPE_PACKAGE => $request['packageRef'] ?? null,
            VoiceTariffBinding::SCOPE_SERVICE => $request['serviceRef'] ?? null,
        ];
        $best = null;
        $bestRank = -1;
        $bestPriority = PHP_INT_MIN;

        foreach ($snapshot['bindings'] as $binding) {
            $scope = $binding['binding_scope'];
            $expected = $references[$scope] ?? null;
            if ($expected === null || (string) $binding['binding_ref'] !== (string) $expected) {
                continue;
            }
            if (! $this->isEffective($binding['effective_from'] ?? null, $binding['effective_to'] ?? null, $at)) {
                continue;
            }
            $rank = self::SCOPE_RANK[$scope] ?? 0;
            $priority = (int) ($binding['priority'] ?? 0);
            if ($rank > $bestRank || ($rank === $bestRank && $priority > $bestPriority)) {
                [$best, $bestRank, $bestPriority] = [$binding, $rank, $priority];
            }
        }

        if ($best === null) {
            return null;
        }
        foreach ($snapshot['plans'] as $plan) {
            if ($plan['tariff_plan_id'] === $best['tariff_plan_id']
                && $this->isEffective($plan['effective_from'] ?? null, $plan['effective_to'] ?? null, $at)) {
                return $plan;
            }
        }

        return null;
    }

    private function resolveZone(array $snapshot, string $called, Carbon $at): ?array
    {
        $best = null;
        $bestLength = -1;
        $bestPriority = PHP_INT_MIN;
        foreach ($snapshot['prefixes'] as $prefix) {
            $value = (string) $prefix['prefix'];
            if ($value === '' || ! str_starts_with($called, $value)
                || ! $this->isEffective($prefix['effective_from'] ?? null, $prefix['effective_to'] ?? null, $at)) {
                continue;
            }
            $length = strlen($value);
            $priority = (int) ($prefix['match_priority'] ?? 0);
            if ($length > $bestLength || ($length === $bestLength && $priority > $bestPriority)) {
                [$best, $bestLength, $bestPriority] = [$prefix, $length, $priority];
            }
        }
        if ($best === null) {
            return null;
        }

        foreach ($snapshot['zones'] as $zone) {
            if ($zone['zone_id'] === $best['zone_id']) {
                return $zone;
            }
        }

        return null;
    }

    private function resolveTimeBand(array $snapshot, Carbon $at): ?array
    {
        $anytime = null;
        foreach ($snapshot['timeBands'] as $band) {
            if (($band['time_band_code'] ?? null) === 'ANYTIME') {
                $anytime = $band;
            }
            if ($this->bandContains($band, $at)) {
                return $band;
            }
        }

        return $anytime;
    }

    private function bandContains(array $band, Carbon $at): bool
    {
        $local = $at->copy()->setTimezone($band['timezone'] ?? 'UTC');
        $days = array_filter(array_map('trim', explode(',', (string) ($band['days_of_week'] ?? ''))));
        if ($days !== []) {
            $day = strtoupper(substr($local->format('D'), 0, 3));
            if (! in_array($day, array_map('strtoupper', $days), true)) {
                return false;
            }
        }

        $now = $local->format('H:i:s');

        return $now >= (string) ($band['start_time_local'] ?? '00:00:00')
            && $now <= (string) ($band['end_time_local'] ?? '23:59:59');
    }

    private function resolveRate(array $snapshot, string $planId, string $zoneId, ?string $bandId, string $direction, Carbon $at): ?array
    {
        $best = null;
        $bestFrom = null;
        foreach ($snapshot['rates'] as $rate) {
            if ($rate['tariff_plan_id'] !== $planId || $rate['zone_id'] !== $zoneId || $rate['call_direction'] !== $direction) {
                continue;
            }
            if ($bandId !== null && $rate['time_band_id'] !== $bandId) {
                continue;
            }
            if (! $this->isEffective($rate['effective_from'] ?? null, $rate['effective_to'] ?? null, $at)) {
                continue;
            }
            $from = $this->timestamp($rate['effective_from'] ?? null);
            if ($best === null || ($from !== null && ($bestFrom === null || $from->greaterThan($bestFrom)))) {
                [$best, $bestFrom] = [$rate, $from];
            }
        }

        return $best;
    }

    private function isEffective(mixed $from, mixed $to, Carbon $at): bool
    {
        $start = $this->timestamp($from);
        $end = $this->timestamp($to);

        return ! ($start !== null && $at->lessThan($start))
            && ! ($end !== null && $at->greaterThan($end));
    }

    private function timestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }

    private function quarantine(string $reason): array
    {
        return ['resolutionStatus' => 'QUARANTINE', 'reason' => $reason];
    }
}
