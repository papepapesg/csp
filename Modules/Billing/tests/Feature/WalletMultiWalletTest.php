<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

/**
 * PLM-CFG-03 multi-wallet: a single (triple-play) subscription holds more than one
 * wallet — MONEY_KES for Internet+TV settlement and VOICE_KES for usage-based Phone
 * — addressed by walletRef and selected at charge time by charging_precedence.
 */
class WalletMultiWalletTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WalletCatalogSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('BILLING_LEAD');
        Sanctum::actingAs($user);
        Context::setOperatorCode('WIK');
    }

    public function test_one_subscription_holds_internet_tv_and_voice_wallets_independently(): void
    {
        $sub = 'sub_triple_play_1';

        // Top up the Internet+TV settlement wallet and the Phone usage wallet separately.
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 1000, 'walletCode' => 'MONEY_KES'], ['Idempotency-Key' => 'tp-money'])
            ->assertCreated()->assertJsonPath('balance_after', '1000.00');
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 300, 'walletCode' => 'VOICE_KES'], ['Idempotency-Key' => 'tp-voice'])
            ->assertCreated()->assertJsonPath('balance_after', '300.00');

        // Voice usage drains VOICE_KES; the bill-cycle charge drains MONEY_KES — independently.
        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 120, 'reason' => 'VOICE_USAGE', 'walletCode' => 'VOICE_KES'])
            ->assertOk()->assertJsonPath('balance_after', '180.00');
        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 400, 'reason' => 'CYCLE_CHARGE', 'walletCode' => 'MONEY_KES'])
            ->assertOk()->assertJsonPath('balance_after', '600.00');

        // Two distinct ledger rows for the same subscription.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'MONEY_KES', 'balance' => 600.00]);
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'VOICE_KES', 'balance' => 180.00]);
    }

    public function test_charge_time_selection_orders_wallets_by_precedence(): void
    {
        $sub = 'sub_triple_play_2';
        $svc = app(WalletService::class);
        $svc->ensureWallet($sub, 'MONEY_KES'); // precedence 100
        $svc->ensureWallet($sub, 'VOICE_KES'); // precedence 90

        $ordered = $svc->resolveChargingWallets($sub, 'PREPAID')->pluck('wallet_code')->all();

        // Lower precedence first: VOICE drains before MONEY.
        $this->assertSame(['VOICE_KES', 'MONEY_KES'], $ordered);
    }

    public function test_postpaid_only_filtering_excludes_prepaid_wallets(): void
    {
        $sub = 'sub_postpaid_1';
        $svc = app(WalletService::class);
        $svc->ensureWallet($sub, 'MONEY_KES');   // PREPAID_ONLY
        $svc->ensureWallet($sub, 'DEPOSIT_KES'); // applicability ANY

        // A POSTPAID subscription cannot charge the prepaid-only money wallet.
        $codes = $svc->resolveChargingWallets($sub, 'POSTPAID')->pluck('wallet_code')->all();
        $this->assertSame(['DEPOSIT_KES'], $codes);
    }

    public function test_non_refillable_wallet_rejects_topup(): void
    {
        $sub = 'sub_promo_1';
        // PROMO_KES is refillable=false (one-shot promotional credit).
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 50, 'walletCode' => 'PROMO_KES'], ['Idempotency-Key' => 'tp-promo'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'WALLET_NOT_REFILLABLE');
    }

    public function test_unknown_wallet_ref_is_rejected(): void
    {
        $sub = 'sub_x';
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 50, 'walletCode' => 'NOT_A_WALLET'], ['Idempotency-Key' => 'tp-x'])
            ->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_WALLET_REF');
    }
}
