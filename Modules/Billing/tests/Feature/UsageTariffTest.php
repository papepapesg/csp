<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\RatedEvent;
use Modules\Billing\Services\MediationRatingService;
use Modules\Catalog\Models\UsageTariff;
use Tests\TestCase;

/**
 * PLM-CFG-07: DATA/SMS rating reads the per-operator usage_tariff catalog (config),
 * falling back to the built-in default only when no row exists.
 */
class UsageTariffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function rate(string $type, float $qty, string $ref): RatedEvent
    {
        $med = app(MediationRatingService::class);
        $med->ingest([['usage_type' => $type, 'quantity' => $qty, 'source_ref' => $ref]]);
        $med->ratePending('WIK');

        return RatedEvent::query()->where('operator_code', 'WIK')->latest('created_at')->firstOrFail();
    }

    public function test_configured_data_rate_overrides_the_default(): void
    {
        UsageTariff::query()->create(['operator_code' => 'WIK', 'usage_type' => 'DATA', 'rate_per_unit' => 0.75, 'unit' => 'MB']);

        $rated = $this->rate('DATA', 100, 'cdr-data');
        $this->assertSame('0.7500', (string) $rated->rate);
        $this->assertSame('75.0000', (string) $rated->amount); // 100 MB * 0.75, not the 0.50 default
    }

    public function test_falls_back_to_default_when_no_tariff_configured(): void
    {
        $rated = $this->rate('SMS', 10, 'cdr-sms');
        $this->assertSame('10.0000', (string) $rated->amount); // 10 * 1.00 default
    }
}
