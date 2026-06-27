<?php

namespace Modules\Billing\Mediation\Services;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Mediation\Models\RatedEvent;
use Modules\Billing\Mediation\Models\UsageRecord;
use Modules\Catalog\Models\UsageTariff;
use Modules\Catalog\Models\VoiceTariff;
use Modules\Catalog\Services\UsageRatingService;

/**
 * MED-01 mediation + RAT-01 rating. ingest() deduplicates raw usage by source_ref
 * (idempotent CDR intake). rate() prices RECEIVED records: voice resolves a
 * PLM-CFG-07 voice_tariff by destination (per-minute + setup fee, min-charge
 * applied), data/SMS use a flat per-unit rate, producing a rated_event BIL-01 bills.
 */
class MediationRatingService
{
    private const DATA_RATE_PER_MB = 0.50;

    private const SMS_RATE = 1.00;

    public function __construct(
        private readonly EventBus $events,
        private readonly SophixCache $cache,
        private readonly UsageRatingService $usage,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $batch  records with usage_type, quantity, source_ref, ...
     * @return array{ingested:int, duplicates:int}
     */
    public function ingest(array $batch): array
    {
        $operator = Context::operatorCode();
        $ingested = 0;
        $duplicates = 0;
        foreach ($batch as $r) {
            $exists = UsageRecord::query()->where('operator_code', $operator)->where('source_ref', $r['source_ref'])->exists();
            if ($exists) {
                $duplicates++;

                continue;
            }
            UsageRecord::query()->create([
                'usage_id' => Id::make('use'),
                'subscription_id' => $r['subscription_id'] ?? null,
                'account_id' => $r['account_id'] ?? null,
                'usage_type' => $r['usage_type'],
                'destination' => $r['destination'] ?? null,
                'quantity' => (float) $r['quantity'],
                'source_ref' => $r['source_ref'],
                'occurred_at' => $r['occurred_at'] ?? now(),
                'status' => 'RECEIVED',
                'raw' => $r['raw'] ?? null,
            ]);
            $ingested++;
        }

        return ['ingested' => $ingested, 'duplicates' => $duplicates];
    }

    /** Rate all RECEIVED usage (RAT-01). @return array{rated:int} */
    public function ratePending(?string $operator = null): array
    {
        $operator ??= Context::operatorCode();
        $rated = 0;
        UsageRecord::query()->where('operator_code', $operator)->where('status', 'RECEIVED')->chunkById(500, function ($records) use (&$rated) {
            foreach ($records as $record) {
                $this->rate($record);
                $rated++;
            }
        }, 'usage_id');

        return ['rated' => $rated];
    }

    public function rate(UsageRecord $record): RatedEvent
    {
        return DB::transaction(function () use ($record) {
            [$rate, $amount, $tariffCode] = $this->price($record);

            $event = RatedEvent::query()->create([
                'rated_id' => Id::make('rat'),
                'operator_code' => $record->operator_code,
                'usage_id' => $record->usage_id,
                'subscription_id' => $record->subscription_id,
                'tariff_code' => $tariffCode,
                'rate' => $rate,
                'amount' => round($amount, 4),
            ]);
            $record->update(['status' => 'RATED']);

            $this->events->publish(new DomainEvent(
                type: 'UsageRated',
                topic: 'billing.usage',
                payload: ['ratedId' => $event->rated_id, 'usageId' => $record->usage_id, 'amount' => (string) $event->amount, 'type' => $record->usage_type],
                aggregateType: 'RatedEvent',
                aggregateId: $event->rated_id,
            ));

            return $event;
        });
    }

    /** @return array{0:float,1:float,2:?string} [rate, amount, tariffCode] */
    private function price(UsageRecord $record): array
    {
        if ($record->usage_type === 'VOICE') {
            // FOUNDATION_CACHE pricing read (1h TTL): rating consumes the PLM tariff
            // catalogs cache-aside; PostgreSQL stays the source of truth.
            $dest = $record->destination ?? 'ONNET';
            $attrs = $this->cache->remember('plm', 'voice-tariff', "{$record->operator_code}:{$dest}", SophixCache::TTL_PRICING,
                fn () => VoiceTariff::query()->where('operator_code', $record->operator_code)
                    ->where('destination', $dest)->first()?->getAttributes());
            $tariff = $attrs ? VoiceTariff::hydrate([$attrs])->first() : null;
            $rate = (float) ($tariff->rate_per_min ?? 0);
            $setup = (float) ($tariff->setup_fee ?? 0);
            $seconds = max((float) $record->quantity, (float) ($tariff->min_charge_seconds ?? 0));
            $amount = $setup + ($seconds / 60) * $rate;

            return [$rate, $amount, $tariff->code ?? null];
        }
        // DATA / SMS (and any future metered type) → the generic usage rating engine
        // (reservation/pulse + allowance + fees + policy). Falls back to the flat default
        // only when the operator has not configured a usage_tariff row.
        $rated = $this->usage->rate([
            'operatorCode' => $record->operator_code,
            'usageType' => $record->usage_type,
            'quantity' => (float) $record->quantity,
            // allowance balance is owned by Billing; once wired it passes remaining units here.
            'remainingAllowanceUnits' => (float) ($record->remaining_allowance_units ?? 0),
        ]);
        if ($rated['resolved']) {
            return [$rated['rate'], $rated['amount'], $rated['tariffCode']];
        }

        $default = $record->usage_type === 'DATA' ? self::DATA_RATE_PER_MB : self::SMS_RATE;

        return [$default, (float) $record->quantity * $default, $record->usage_type.'_FLAT'];
    }
}
