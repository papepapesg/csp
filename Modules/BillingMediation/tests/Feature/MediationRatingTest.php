<?php

namespace Modules\Billing\Mediation\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Mediation\Models\RatedEvent;
use Modules\Billing\Mediation\Models\UsageRecord;
use Modules\Catalog\Rating\Models\UsageTariff;
use Modules\Catalog\Rating\Models\VoiceTariff;
use Tests\TestCase;

/**
 * MED-01 mediation + RAT-01 rating — raw CDR intake (idempotent on source_ref) and pricing
 * into immutable rated_event rows BIL-01 later bills. Voice prices from the PLM-CFG-07
 * voice_tariff by destination; DATA/SMS go through the generic usage-tariff engine, falling
 * back to a flat default only when no tariff is configured.
 */
class MediationRatingTest extends TestCase
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

    /**
     * EXPECTATION — CDR intake is idempotent; rating prices by type.
     * Ingesting usage dedupes on source_ref (a replayed CDR is a duplicate, not a
     * second charge); the rating worker then prices voice from the per-minute
     * voice_tariff (120s = 12.00) and data at the flat default (100MB = 50.00),
     * each an immutable rated_event, and emits UsageRated.
     */
    public function test_ingest_dedupes_and_rates_voice_and_data(): void
    {
        VoiceTariff::query()->create([
            'voice_tariff_id' => 'vtar_1', 'operator_code' => 'WIK', 'code' => 'OFFNET_STD',
            'name' => 'Off-net', 'destination' => 'OFFNET', 'rate_per_min' => 6.0, 'setup_fee' => 0, 'min_charge_seconds' => 0,
        ]);

        // Ingest: 2 records, one a duplicate source_ref.
        $this->postJson('/api/usage', ['records' => [
            ['usage_type' => 'VOICE', 'quantity' => 120, 'destination' => 'OFFNET', 'source_ref' => 'cdr-1'],
            ['usage_type' => 'DATA', 'quantity' => 100, 'source_ref' => 'cdr-2'],
        ]], ['Idempotency-Key' => 'u1'])->assertOk()->assertJsonPath('ingested', 2);

        $this->postJson('/api/usage', ['records' => [
            ['usage_type' => 'VOICE', 'quantity' => 120, 'destination' => 'OFFNET', 'source_ref' => 'cdr-1'],
        ]], ['Idempotency-Key' => 'u2'])->assertOk()->assertJsonPath('duplicates', 1);

        // Rate the pending usage (offline worker).
        Artisan::call('sophix:billing:rate-usage', ['--operator' => 'WIK']);

        // Voice: 120s = 2 min * 6.0 = 12.00; Data: 100MB * 0.50 = 50.00.
        $this->assertDatabaseHas('rated_event', ['usage_id' => null === null ? UsageRecord::where('source_ref', 'cdr-1')->value('usage_id') : null]);
        $voice = RatedEvent::query()->where('tariff_code', 'OFFNET_STD')->first();
        $this->assertEqualsWithDelta(12.0, (float) $voice->amount, 0.001);
        $data = RatedEvent::query()->where('tariff_code', 'DATA_FLAT')->first();
        $this->assertEqualsWithDelta(50.0, (float) $data->amount, 0.001);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'UsageRated']);
    }

    /**
     * EXPECTATION — a configured usage_tariff overrides the flat default.
     * When the operator has authored a rich DATA tariff, rating routes through the
     * generic usage engine (reservation/pulse + allowance) instead of the built-in
     * flat rate: 100 MB × 2.0 = 200, tagged DATA_TARIFF.
     */
    public function test_data_uses_the_generic_usage_tariff_when_configured(): void
    {
        // A configured rich tariff routes DATA through the generic engine (not the flat default).
        UsageTariff::query()->create([
            'operator_code' => 'WIK', 'usage_type' => 'DATA', 'rate_per_unit' => 2.0, 'unit' => 'MB', 'unit_type' => 'MB',
            'initial_increment_units' => 1, 'subsequent_increment_units' => 1, 'min_charge' => 0, 'included_units' => 0, 'active' => true,
        ]);

        $this->postJson('/api/usage', ['records' => [['usage_type' => 'DATA', 'quantity' => 100, 'source_ref' => 'cdr-d1']]], ['Idempotency-Key' => 'ud'])->assertOk();
        Artisan::call('sophix:billing:rate-usage', ['--operator' => 'WIK']);

        $data = RatedEvent::query()->where('tariff_code', 'DATA_TARIFF')->first();
        $this->assertNotNull($data);
        $this->assertEqualsWithDelta(200.0, (float) $data->amount, 0.001); // 100 MB × 2.0
    }
}
