<?php

namespace Modules\Catalog\Tests\Feature;

use App\Foundation\Cache\SophixCache;
use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Events\Outbox\OutboxEvent;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Listeners\CatalogCacheInvalidator;
use Modules\Catalog\Models\VoiceDestinationPrefix;
use Modules\Catalog\Models\VoiceDestinationZone;
use Modules\Catalog\Models\VoiceTariffBinding;
use Modules\Catalog\Models\VoiceTariffPlan;
use Modules\Catalog\Models\VoiceTariffRate;
use Modules\Catalog\Models\VoiceTimeBand;
use Modules\Catalog\Support\CatalogCacheKeys;
use Tests\TestCase;

/**
 * PLM-CFG-07 Voice Tariff Catalog: lifecycle + the rating-lookup rules
 * (DD §14) — longest-prefix match, overlap rejection, emergency zero-rating,
 * effective-date selection, binding precedence, and the §9 worked example.
 */
class VoiceTariffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
    }

    // ------------------------------------------------------------- helpers

    /** Create an ACTIVE plan via the API and return its id. */
    private function activePlan(string $code = 'WIK_RES_VOICE_STD_2026'): string
    {
        $res = $this->postJson('/api/plm/voice-tariff-plans', [
            'tariff_plan_code' => $code,
            'display_name' => 'Residential Voice Standard 2026',
            'billing_mode' => 'BOTH',
            'currency_code' => 'KES',
            'effective_from' => '2026-06-01T00:00:00Z',
        ], ['Idempotency-Key' => "plan-{$code}"])->assertCreated()->assertJsonPath('status', 'DRAFT');

        $id = $res->json('tariff_plan_id');
        $this->postJson("/api/plm/voice-tariff-plans/{$id}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');

        return $id;
    }

    private function zone(string $code, string $type = 'NATIONAL', string $policy = 'CHARGEABLE'): string
    {
        return $this->postJson('/api/plm/voice-destination-zones', [
            'zone_code' => $code, 'zone_name' => $code, 'zone_type' => $type, 'default_charge_policy' => $policy,
        ])->assertCreated()->json('zone_id');
    }

    private function anytimeBand(): string
    {
        return $this->postJson('/api/plm/voice-time-bands', [
            'time_band_code' => 'ANYTIME', 'display_name' => 'Any time',
            'days_of_week' => 'MON,TUE,WED,THU,FRI,SAT,SUN',
            'start_time_local' => '00:00:00', 'end_time_local' => '23:59:59', 'timezone' => 'Africa/Nairobi',
        ])->assertCreated()->json('time_band_id');
    }

    private function prefix(string $prefix, string $zoneId, int $priority = 0): void
    {
        $this->postJson('/api/plm/voice-destination-prefixes', [
            'prefix' => $prefix, 'zone_id' => $zoneId, 'match_priority' => $priority,
            'effective_from' => '2026-06-01T00:00:00Z',
        ])->assertCreated();
    }

    private function binding(string $scope, string $ref, string $planId, int $priority = 100): void
    {
        $this->postJson('/api/plm/voice-tariff-bindings', [
            'binding_scope' => $scope, 'binding_ref' => $ref, 'tariff_plan_id' => $planId,
            'priority' => $priority, 'effective_from' => '2026-06-01T00:00:00Z',
        ])->assertCreated();
    }

    /** @param array<string,mixed> $overrides */
    private function rate(string $planId, string $zoneId, string $bandId, array $overrides = []): void
    {
        $this->postJson('/api/plm/voice-tariff-rates', array_merge([
            'tariff_plan_id' => $planId, 'zone_id' => $zoneId, 'time_band_id' => $bandId,
            'call_direction' => 'OUTBOUND', 'unit_type' => 'MINUTE', 'unit_price' => 3.0,
            'initial_increment_seconds' => 60, 'subsequent_increment_seconds' => 60,
            'taxable_kind' => 'SERVICE', 'taxable_ref' => 'svc_VOIP_STD',
            'effective_from' => '2026-06-01T00:00:00Z',
        ], $overrides))->assertCreated();
    }

    // ------------------------------------------------------------- §14 tests

    public function test_create_draft_plan_zones_prefixes_rates_then_activate(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($plan, $zone, $band);

        $this->assertDatabaseHas('voice_tariff_plan', ['tariff_plan_code' => 'WIK_RES_VOICE_STD_2026', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('voice_tariff_rate', ['tariff_plan_id' => $plan, 'zone_id' => $zone, 'status' => 'ACTIVE']);
    }

    public function test_section_9_example_rating_lookup_returns_ke_mobile_rate(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($plan, $zone, $band);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK',
            'subscriptionId' => 'SUB-700045',
            'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+254711222333',
            'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ])
            ->assertOk()
            ->assertJsonPath('resolutionStatus', 'RESOLVED')
            ->assertJsonPath('tariffPlanCode', 'WIK_RES_VOICE_STD_2026')
            ->assertJsonPath('zoneCode', 'KE_MOBILE')
            ->assertJsonPath('timeBandCode', 'ANYTIME')
            ->assertJsonPath('chargePolicy', 'CHARGEABLE')
            ->assertJsonPath('unitType', 'MINUTE')
            ->assertJsonPath('unitPrice', fn ($v) => (float) $v === 3.0) // JSON drops the .0 on whole numbers
            ->assertJsonPath('currency', 'KES')
            ->assertJsonPath('initialIncrementSeconds', 60)
            ->assertJsonPath('subsequentIncrementSeconds', 60)
            ->assertJsonPath('taxableKind', 'SERVICE')
            ->assertJsonPath('taxableRef', 'svc_VOIP_STD');
    }

    public function test_rate_call_applies_reservation_pulse_and_allowance(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($plan, $zone, $band, ['unit_price' => 3.0, 'initial_increment_seconds' => 60, 'subsequent_increment_seconds' => 60]);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        $base = [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+254711222333', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ];

        // 90s → reservation 60 + one 60s pulse = 120 billable = 2 min × 3.0 = 6.0
        $this->postJson('/api/plm/voice-rating/rate', $base + ['durationSeconds' => 90])
            ->assertOk()
            ->assertJsonPath('resolutionStatus', 'RATED')
            ->assertJsonPath('chargeStatus', 'CHARGED')
            ->assertJsonPath('billableSeconds', 120)
            ->assertJsonPath('chargeableSeconds', 120)
            ->assertJsonPath('amount', fn ($v) => (float) $v === 6.0);

        // Same call with 60s of allowance left → burn 60, charge the other 60s (1 min) = 3.0
        $this->postJson('/api/plm/voice-rating/rate', $base + ['durationSeconds' => 90, 'remainingAllowanceSeconds' => 60])
            ->assertOk()
            ->assertJsonPath('allowanceConsumedSeconds', 60)
            ->assertJsonPath('chargeableSeconds', 60)
            ->assertJsonPath('amount', fn ($v) => (float) $v === 3.0);
    }

    public function test_rate_call_zero_rated_charges_nothing(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($plan, $zone, $band, ['unit_price' => 3.0, 'charge_policy' => 'ZERO_RATED']);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        $this->postJson('/api/plm/voice-rating/rate', [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+254711222333', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00', 'durationSeconds' => 90,
        ])
            ->assertOk()
            ->assertJsonPath('chargeStatus', 'ZERO_RATED')
            ->assertJsonPath('amount', fn ($v) => (float) $v === 0.0);
    }

    public function test_longest_prefix_match_picks_most_specific(): void
    {
        $plan = $this->activePlan();
        $mobile = $this->zone('KE_MOBILE');
        $safaricom = $this->zone('KE_SAFARICOM');
        $band = $this->anytimeBand();

        $this->prefix('+2547', $mobile);
        $this->prefix('+254711', $safaricom); // longer → wins for +254711...
        $this->rate($plan, $mobile, $band, ['unit_price' => 3.0]);
        $this->rate($plan, $safaricom, $band, ['unit_price' => 1.5]);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+254711222333', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ])->assertOk()->assertJsonPath('zoneCode', 'KE_SAFARICOM')->assertJsonPath('unitPrice', 1.5);
    }

    public function test_overlap_validation_rejects_ambiguous_rates(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->rate($plan, $zone, $band); // 2026-06-01 → open

        // Same plan/zone/band/direction with an overlapping open period → rejected.
        $this->postJson('/api/plm/voice-tariff-rates', [
            'tariff_plan_id' => $plan, 'zone_id' => $zone, 'time_band_id' => $band,
            'call_direction' => 'OUTBOUND', 'unit_type' => 'MINUTE', 'unit_price' => 4.0,
            'effective_from' => '2026-07-01T00:00:00Z',
        ])->assertStatus(422)->assertJsonPath('errorCode', 'R-VOICE-TAR-08');

        // The validate-overlap endpoint also reports the conflict.
        $this->postJson('/api/plm/voice-tariff-rates/validate-overlap', [
            'rates' => [[
                'tariff_plan_id' => $plan, 'zone_id' => $zone, 'time_band_id' => $band,
                'call_direction' => 'OUTBOUND', 'unit_type' => 'MINUTE', 'unit_price' => 4.0,
                'effective_from' => '2026-08-01T00:00:00Z',
            ]],
        ])->assertOk()->assertJsonPath('valid', false);
    }

    public function test_emergency_zone_is_zero_rated(): void
    {
        $plan = $this->activePlan();
        $emergency = $this->zone('EMERGENCY', 'EMERGENCY', 'ZERO_RATED');
        $band = $this->anytimeBand();
        $this->prefix('999', $emergency);
        // A configured rate still resolves to ZERO_RATED / unitPrice 0 (R-VOICE-TAR-04).
        $this->rate($plan, $emergency, $band, ['unit_price' => 5.0, 'charge_policy' => 'ZERO_RATED']);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '999', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ])->assertOk()
            ->assertJsonPath('zoneCode', 'EMERGENCY')
            ->assertJsonPath('chargePolicy', 'ZERO_RATED')
            ->assertJsonPath('unitPrice', fn ($v) => (float) $v === 0.0);
    }

    public function test_effective_date_lookup_selects_historical_rate(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        // Old rate ends, new rate begins. A call before the cutover rates at the old price.
        $this->rate($plan, $zone, $band, ['unit_price' => 2.0, 'effective_from' => '2026-06-01T00:00:00Z', 'effective_to' => '2026-06-30T23:59:59Z']);
        $this->rate($plan, $zone, $band, ['unit_price' => 3.5, 'effective_from' => '2026-07-01T00:00:00Z']);

        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+254711222333', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-15T10:00:00+03:00',
        ])->assertOk()->assertJsonPath('unitPrice', fn ($v) => (float) $v === 2.0);
    }

    public function test_binding_priority_selects_subscription_override_before_package(): void
    {
        $stdPlan = $this->activePlan('WIK_STD');
        $vipPlan = $this->activePlan('WIK_VIP');
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($stdPlan, $zone, $band, ['unit_price' => 3.0]);
        $this->rate($vipPlan, $zone, $band, ['unit_price' => 1.0]);

        // PACKAGE binding → std plan; SUBSCRIPTION_OVERRIDE → vip plan. Override wins.
        $this->binding('PACKAGE', 'pkg_INET_VOICE_STD', $stdPlan);
        $this->binding('SUBSCRIPTION_OVERRIDE', 'SUB-700045', $vipPlan);

        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK', 'subscriptionId' => 'SUB-700045', 'packageRef' => 'pkg_INET_VOICE_STD',
            'calledNumberNormalized' => '+254711222333', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ])->assertOk()
            ->assertJsonPath('tariffPlanCode', 'WIK_VIP')
            ->assertJsonPath('unitPrice', fn ($v) => (float) $v === 1.0);
    }

    public function test_unknown_prefix_quarantines(): void
    {
        $plan = $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $band = $this->anytimeBand();
        $this->prefix('+2547', $zone);
        $this->rate($plan, $zone, $band);
        $this->binding('SERVICE', 'svc_VOIP_STD', $plan);

        // R-VOICE-TAR-07: a number with no matching prefix must quarantine, not zero-rate.
        $this->postJson('/api/plm/voice-rating/lookup', [
            'operatorCode' => 'WIK', 'serviceRef' => 'svc_VOIP_STD',
            'calledNumberNormalized' => '+1202555000', 'callDirection' => 'OUTBOUND',
            'callStartedAt' => '2026-06-02T10:15:00+03:00',
        ])->assertOk()->assertJsonPath('resolutionStatus', 'QUARANTINE');
    }

    public function test_bulk_prefix_import_rejects_duplicate_active(): void
    {
        $this->activePlan();
        $zone = $this->zone('KE_MOBILE');
        $this->prefix('+2547', $zone); // existing ACTIVE prefix

        $this->postJson('/api/plm/voice-destination-prefixes/bulk-import', [
            'prefixes' => [
                ['prefix' => '+25420', 'zone_id' => $zone, 'effective_from' => '2026-06-01T00:00:00Z'],
                ['prefix' => '+2547', 'zone_id' => $zone, 'effective_from' => '2026-06-01T00:00:00Z'], // dup
            ],
        ])->assertStatus(422)->assertJsonPath('errorCode', 'R-VOICE-TAR-03');

        // The whole batch is rejected — no partial import.
        $this->assertDatabaseMissing('voice_destination_prefix', ['prefix' => '+25420']);
    }

    public function test_rate_change_event_invalidates_rating_cache(): void
    {
        $cache = app(SophixCache::class);
        // Prime a snapshot under the operator key.
        $cache->remember(...CatalogCacheKeys::voiceTariff('WIK'), ttlSeconds: 60, source: fn () => ['plans' => []]);
        $this->assertNotNull($cache->remember(...CatalogCacheKeys::voiceTariff('WIK'), ttlSeconds: 60, source: fn () => null));

        // The CatalogCacheInvalidator evicts the snapshot on a rate-changed event.
        $row = OutboxEvent::query()->create([
            'event_id' => 'evt_test', 'event_type' => CatalogEvents::VOICE_TARIFF_RATE_CHANGED,
            'topic' => CatalogEvents::TOPIC, 'aggregate_type' => 'VoiceTariffRate', 'aggregate_id' => 'vtr_x',
            'operator_code' => 'WIK', 'correlation_id' => 'corr_test', 'payload' => ['operatorCode' => 'WIK'], 'headers' => [],
        ]);
        app(CatalogCacheInvalidator::class)->handle(new OutboxEventPublished($row));

        // Next read misses → rebuild source runs.
        $rebuilt = false;
        $cache->remember(...CatalogCacheKeys::voiceTariff('WIK'), ttlSeconds: 60, source: function () use (&$rebuilt) {
            $rebuilt = true;

            return ['plans' => ['rebuilt']];
        });
        $this->assertTrue($rebuilt);
    }
}
