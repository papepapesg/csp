<?php

namespace Modules\Catalog\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Rating\Models\UsageTariff;
use Modules\Catalog\Rating\Services\UsageRatingService;
use Tests\TestCase;

/**
 * Generic usage rating (DATA / SMS / any metered type): one engine applies
 * reservation+pulse rounding, allowance burn-down, per-unit price + fees and the
 * charge policy — the same shape voice has, without a per-service service.
 */
class UsageRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_applies_increment_allowance_and_per_unit_price(): void
    {
        UsageTariff::query()->create([
            'operator_code' => 'WIK', 'usage_type' => 'DATA', 'rate_per_unit' => 2.0, 'unit' => 'MB', 'unit_type' => 'MB',
            'initial_increment_units' => 1, 'subsequent_increment_units' => 1, 'included_units' => 0, 'active' => true,
        ]);

        // 100 MB with 50 MB of bundle allowance → charge 50 MB × 2.0 = 100.
        $r = app(UsageRatingService::class)->rate([
            'operatorCode' => 'WIK', 'usageType' => 'DATA', 'quantity' => 100, 'remainingAllowanceUnits' => 50,
        ]);

        $this->assertTrue($r['resolved']);
        $this->assertSame('CHARGED', $r['chargeStatus']);
        $this->assertEqualsWithDelta(50.0, $r['allowanceConsumedUnits'], 0.001);
        $this->assertEqualsWithDelta(50.0, $r['chargeableUnits'], 0.001);
        $this->assertEqualsWithDelta(100.0, $r['amount'], 0.001);
        $this->assertSame('DATA_TARIFF', $r['tariffCode']);
    }

    public function test_sms_per_message_min_charge_and_setup_fee(): void
    {
        UsageTariff::query()->create([
            'operator_code' => 'WIK', 'usage_type' => 'SMS', 'rate_per_unit' => 5.0, 'unit' => 'MESSAGE', 'unit_type' => 'MESSAGE',
            'setup_fee' => 1.0, 'min_charge' => 20.0, 'active' => true,
        ]);

        // 3 messages → 3×5 + 1 setup = 16, floored at the 20 minimum.
        $r = app(UsageRatingService::class)->rate(['operatorCode' => 'WIK', 'usageType' => 'SMS', 'quantity' => 3]);
        $this->assertEqualsWithDelta(20.0, $r['amount'], 0.001);
    }

    public function test_zero_rated_charges_nothing_and_unknown_type_is_unresolved(): void
    {
        UsageTariff::query()->create(['operator_code' => 'WIK', 'usage_type' => 'DATA', 'rate_per_unit' => 2.0, 'charge_policy' => 'ZERO_RATED', 'active' => true]);

        $z = app(UsageRatingService::class)->rate(['operatorCode' => 'WIK', 'usageType' => 'DATA', 'quantity' => 100]);
        $this->assertSame('ZERO_RATED', $z['chargeStatus']);
        $this->assertEqualsWithDelta(0.0, $z['amount'], 0.001);

        // No tariff for VOICE here → unresolved (caller may fall back / use the voice engine).
        $u = app(UsageRatingService::class)->rate(['operatorCode' => 'WIK', 'usageType' => 'VOICE', 'quantity' => 60]);
        $this->assertFalse($u['resolved']);
    }
}
