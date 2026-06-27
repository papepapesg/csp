<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Wallet\Models\Wallet;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

/** BIL-05 / PLM-CFG-03: wallet expiry sweep (R-W-9) + points redemption at charge (R-W-15). */
class WalletExpiryAndPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
        $this->seed(WalletCatalogSeeder::class);
    }

    public function test_topup_to_an_expiring_wallet_sets_validity_and_sweep_zeroes_it(): void
    {
        $svc = app(WalletService::class);
        // LOYALTY_POINTS_KES expires after 365 days and is refillable.
        $wallet = $svc->ensureWallet('sub_1', 'LOYALTY_POINTS_KES');
        $svc->credit($wallet, 500, 'TOPUP');
        $this->assertNotNull($wallet->fresh()->expires_at);

        // Force the validity into the past and sweep.
        Wallet::query()->whereKey($wallet->wallet_id)->update(['expires_at' => now()->subDay()]);
        $this->assertSame(1, $svc->expireBalances('WIK'));
        $this->assertEquals(0, (float) $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transaction', ['wallet_id' => $wallet->wallet_id, 'reason' => 'EXPIRY']);
    }

    public function test_points_wallet_is_valued_and_debited_in_points_at_charge(): void
    {
        $svc = app(WalletService::class);
        // 10,000 points at 0.01 KES/point = 100.00 KES of value.
        $points = $svc->ensureWallet('sub_2', 'LOYALTY_POINTS_KES');
        $svc->credit($points, 10000, 'TOPUP');

        // A 30 KES charge settles from points: 30 / 0.01 = 3000 points debited.
        $result = $svc->settleFromWallets('sub_2', 30.0, 'USAGE_CHARGE');
        $this->assertTrue($result['settled']);
        $this->assertEqualsWithDelta(100.0, $result['available'], 0.01); // currency value of the points
        $this->assertEquals(7000, (float) $points->fresh()->balance);     // 10000 - 3000 points
    }
}
