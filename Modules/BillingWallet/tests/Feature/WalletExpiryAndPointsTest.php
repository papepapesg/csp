<?php

namespace Modules\Billing\Wallet\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Wallet\Models\Wallet;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Tests\TestCase;

/** BIL-05 / PLM-CFG-03: wallet expiry sweep (R-W-9) + points redemption at charge (R-W-15). */
class WalletExpiryAndPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
        $this->seed(WalletTypeSeeder::class);
    }

    /**
     * EXPECTATION — expiring wallets live on a validity window the top-up starts.
     * Given a catalog wallet that expires (LOYALTY_POINTS, 365 days),
     * when it is topped up, then expires_at is (re)set;
     * when the validity passes and the daily sweep runs, then the balance is
     * zeroed by an EXPIRY debit on the ledger — expired value is never spendable.
     */
    public function test_topup_to_an_expiring_wallet_sets_validity_and_sweep_zeroes_it(): void
    {
        $svc = app(WalletService::class);
        // LOYALTY_POINTS expires after 365 days and is refillable.
        $wallet = $svc->ensureWallet('sub_1', 'LOYALTY_POINTS');
        $svc->credit($wallet, 500, 'TOPUP');
        $this->assertNotNull($wallet->fresh()->expires_at);

        // Force the validity into the past and sweep.
        Wallet::query()->whereKey($wallet->wallet_id)->update(['expires_at' => now()->subDay()]);
        $this->assertSame(1, $svc->expireBalances('WIK'));
        $this->assertEquals(0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transaction', ['wallet_id' => $wallet->wallet_id, 'movement_type' => 'EXPIRY']);
    }

    /**
     * EXPECTATION — points are currency at charge time (R-W-15).
     * Given 10,000 points at 0.01 KES/point (= 100 KES of value),
     * when a 30 KES charge settles from wallets,
     * then the charge is valued in CURRENCY but debited in POINTS:
     * 30 / 0.01 = 3,000 points leave the wallet, 7,000 remain.
     */
    public function test_points_wallet_is_valued_and_debited_in_points_at_charge(): void
    {
        $svc = app(WalletService::class);
        // 10,000 points at 0.01 KES/point = 100.00 KES of value.
        $points = $svc->ensureWallet('sub_2', 'LOYALTY_POINTS');
        $svc->credit($points, 10000, 'TOPUP');

        // A 30 KES charge settles from points: 30 / 0.01 = 3000 points debited.
        $result = $svc->settleFromWallets('sub_2', 30.0, 'USAGE_CHARGE');
        $this->assertTrue($result['settled']);
        $this->assertEqualsWithDelta(100.0, $result['available'], 0.01); // currency value of the points
        $this->assertEquals(7000, (float) $points->fresh()->balance);     // 10000 - 3000 points
    }
}
