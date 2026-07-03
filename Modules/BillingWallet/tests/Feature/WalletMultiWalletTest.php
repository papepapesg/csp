<?php

namespace Modules\Billing\Wallet\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Tests\TestCase;

/**
 * PLM-CFG-03 multi-wallet: a single (triple-play) subscription holds more than one
 * wallet — MONEY for Internet+TV settlement and VOICE for usage-based Phone
 * — addressed by walletRef and selected at charge time by charging_precedence.
 */
class WalletMultiWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WalletTypeSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('BILLING_LEAD');
        Sanctum::actingAs($user);
        Context::setOperatorCode('WIK');
    }

    /**
     * EXPECTATION — one subscription, several isolated purses.
     * A triple-play subscription tops up MONEY and VOICE separately;
     * voice usage drains only VOICE and the cycle charge only MONEY —
     * two wallet rows, two ledgers, no cross-contamination.
     */
    public function test_one_subscription_holds_internet_tv_and_voice_wallets_independently(): void
    {
        $sub = 'sub_triple_play_1';

        // Top up the Internet+TV settlement wallet and the Phone usage wallet separately.
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 1000, 'walletCode' => 'MONEY'], ['Idempotency-Key' => 'tp-money'])
            ->assertCreated()->assertJsonPath('balance_after', '1000.00');
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 300, 'walletCode' => 'VOICE'], ['Idempotency-Key' => 'tp-voice'])
            ->assertCreated()->assertJsonPath('balance_after', '300.00');

        // Voice usage drains VOICE; the bill-cycle charge drains MONEY — independently.
        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 120, 'reason' => 'VOICE_USAGE', 'walletCode' => 'VOICE'])
            ->assertOk()->assertJsonPath('balance_after', '180.00');
        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 400, 'reason' => 'CYCLE_CHARGE', 'walletCode' => 'MONEY'])
            ->assertOk()->assertJsonPath('balance_after', '600.00');

        // Two distinct ledger rows for the same subscription.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'MONEY', 'balance' => 600.00]);
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'VOICE', 'balance' => 180.00]);
    }

    /**
     * EXPECTATION — the catalog decides drain order, not the caller.
     * At charge time eligible wallets are ordered by charging_precedence
     * (lower first): VOICE (90) drains before MONEY (100).
     */
    public function test_charge_time_selection_orders_wallets_by_precedence(): void
    {
        $sub = 'sub_triple_play_2';
        $svc = app(WalletService::class);
        $svc->ensureWallet($sub, 'MONEY'); // precedence 100
        $svc->ensureWallet($sub, 'VOICE'); // precedence 90

        $ordered = $svc->resolveChargingWallets($sub)->pluck('wallet_code')->all();

        // Lower precedence first: VOICE drains before MONEY.
        $this->assertSame(['VOICE', 'MONEY'], $ordered);
    }

    /**
     * EXPECTATION — only SETTLEMENT wallets are drained to settle charges.
     * A subscription holding both a MONEY (SETTLEMENT) and a DEPOSIT (held) wallet
     * exposes only MONEY to the charging path — a deposit is security, never spent
     * at cycle. Role decides this, not a prepaid/postpaid applicability flag.
     */
    public function test_only_settlement_wallets_are_charge_sources(): void
    {
        $sub = 'sub_settle_1';
        $svc = app(WalletService::class);
        $svc->ensureWallet($sub, 'MONEY');   // role SETTLEMENT
        $svc->ensureWallet($sub, 'DEPOSIT'); // role DEPOSIT — held, not a charge source

        $codes = $svc->resolveChargingWallets($sub)->pluck('wallet_code')->all();
        $this->assertSame(['MONEY'], $codes);
    }

    /**
     * EXPECTATION — one-shot credits stay one-shot (R-W-11).
     * A wallet the catalog marks refillable=false (promo credit) refuses
     * top-ups with WALLET_NOT_REFILLABLE.
     */
    public function test_non_refillable_wallet_rejects_topup(): void
    {
        $sub = 'sub_promo_1';
        // PROMO is refillable=false (one-shot promotional credit).
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 50, 'walletCode' => 'PROMO'], ['Idempotency-Key' => 'tp-promo'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'WALLET_NOT_REFILLABLE');
    }

    /**
     * EXPECTATION — the PLM-CFG-03 catalog is the source of truth for what
     * wallets exist: an unknown walletRef is refused (UNKNOWN_WALLET_REF),
     * never silently created.
     */
    public function test_unknown_wallet_ref_is_rejected(): void
    {
        $sub = 'sub_x';
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 50, 'walletCode' => 'NOT_A_WALLET'], ['Idempotency-Key' => 'tp-x'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_WALLET_REF');
    }
}
