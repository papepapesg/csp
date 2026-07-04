<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Mediation\Models\RatedEvent;
use Modules\Billing\Mediation\Services\MediationRatingService;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Rating\Models\UsageTariff;
use Tests\TestCase;

/**
 * PLM-CFG-03 R-W-16 end to end: a subscription's included DATA allowance is
 * consumed by usage BEFORE overage rates to currency. This wires the allowance
 * wallet (Billing) into the RAT-01 usage rating engine (Catalog) — the hook the
 * engine always had (remainingAllowanceUnits) is now fed from the wallet.
 */
class MediationAllowanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
        $this->seed(WalletTypeSeeder::class); // provides the DATA_BUNDLE allowance type
        UsageTariff::query()->create(['operator_code' => 'WIK', 'usage_type' => 'DATA', 'rate_per_unit' => 0.75, 'unit' => 'MB']);
    }

    private function rate(float $qty, string $ref, string $sub): RatedEvent
    {
        $med = app(MediationRatingService::class);
        $med->ingest([['usage_type' => 'DATA', 'quantity' => $qty, 'source_ref' => $ref, 'subscription_id' => $sub]]);
        $med->ratePending('WIK');

        return RatedEvent::query()->where('operator_code', 'WIK')->where('subscription_id', $sub)->latest('created_at')->firstOrFail();
    }

    /**
     * EXPECTATION — usage inside the allowance is free and burns the bundle.
     * Given a 5,000 MB DATA bundle granted to the subscription,
     * when 3,000 MB of DATA is rated,
     * then the charge is 0 (all covered) and the bundle drops to 2,000 MB.
     */
    public function test_usage_within_allowance_is_free_and_burns_units(): void
    {
        $wallets = app(WalletService::class);
        $wallets->grantAllowance('sub_in', 'DATA_BUNDLE', 5000);

        $rated = $this->rate(3000, 'cdr-in', 'sub_in');

        $this->assertSame('0.0000', (string) $rated->amount);
        $this->assertEqualsWithDelta(2000.0, $wallets->allowanceBalanceFor('sub_in', 'DATA'), 0.001);
        $this->assertDatabaseHas('wallet_transaction', ['movement_type' => 'ALLOWANCE_USE', 'amount' => 3000.00]);
    }

    /**
     * EXPECTATION — only the overage bills.
     * Given a 2,000 MB bundle,
     * when 3,000 MB is rated,
     * then 2,000 MB is covered (bundle → 0) and the remaining 1,000 MB bills at
     * the DATA rate (1,000 × 0.75 = 750).
     */
    public function test_overage_beyond_allowance_is_charged(): void
    {
        $wallets = app(WalletService::class);
        $wallets->grantAllowance('sub_over', 'DATA_BUNDLE', 2000);

        $rated = $this->rate(3000, 'cdr-over', 'sub_over');

        $this->assertSame('750.0000', (string) $rated->amount); // 1000 MB overage × 0.75
        $this->assertEqualsWithDelta(0.0, $wallets->allowanceBalanceFor('sub_over', 'DATA'), 0.001);
    }

    /**
     * EXPECTATION — no bundle means the whole usage bills, unchanged.
     * A subscription with no DATA allowance rates all 400 MB at the tariff
     * (400 × 0.75 = 300) — the allowance wiring is transparent when absent.
     */
    public function test_no_allowance_bills_the_full_usage(): void
    {
        $rated = $this->rate(400, 'cdr-none', 'sub_none');
        $this->assertSame('300.0000', (string) $rated->amount);
    }
}
