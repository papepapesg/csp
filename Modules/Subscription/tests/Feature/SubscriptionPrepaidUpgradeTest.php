<?php

namespace Modules\Subscription\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\PackageVersion;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Models\Subscription;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;
use Tests\TestCase;

/**
 * BIL-01 prepaid settlement: a PREPAID subscription's upgrade proration is charged
 * against the prepaid wallet balance (PLM-CFG-03), not an invoice. With enough
 * balance the operation proceeds inline; otherwise it parks until a top-up settles it.
 */
class SubscriptionPrepaidUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(ProcessDefinitionSeeder::class);
        $this->seed(DecisionTableSeeder::class);
        $this->seed(WalletTypeSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('SUPER_ADMIN');
        Sanctum::actingAs($user);
        Context::setOperatorCode('WIK');
    }

    private function drain(): void
    {
        Artisan::call('sophix:workflow:work', ['--once' => true]);
    }

    private function package(string $code, float $price): string
    {
        $pkgId = Id::make('pkg');
        $verId = Id::make('pkv');
        Package::query()->create(['id' => $pkgId, 'operator_code' => 'WIK', 'code' => $code, 'name' => $code, 'status' => 'ACTIVE', 'current_version_id' => $verId]);
        PackageVersion::query()->create(['id' => $verId, 'package_id' => $pkgId, 'price' => $price, 'currency' => 'KES', 'effective_from' => now(), 'status' => 'ACTIVE']);

        return $pkgId;
    }

    private function prepaidSubscription(string $pkgId): string
    {
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => $pkgId,
            'package_version_id' => Package::find($pkgId)->current_version_id,
            'status_code' => 'ACTIVE', 'currency' => 'KES', 'billing_mode' => 'PREPAID',
        ]);

        return $sub->subscription_id;
    }

    private function fundWallet(string $sub, float $amount): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->ensureWallet($sub, 'MONEY', 'a1', 'c1');
        $wallets->credit($wallet, $amount, 'TOPUP');
    }

    public function test_prepaid_upgrade_is_paid_from_wallet_balance(): void
    {
        $src = $this->package('PKG_BASIC_P', 1000);
        $tgt = $this->package('PKG_PREMIUM_P', 2500); // +1500 proration
        $sub = $this->prepaidSubscription($src);
        $this->fundWallet($sub, 2000); // covers the 1500 delta

        $this->postJson("/api/subscriptions/{$sub}/upgrade", ['targetPackageRef' => $tgt], ['Idempotency-Key' => 'pu-1'])->assertStatus(202);
        $this->drain(); // settles inline from the wallet, no parking

        $fresh = Subscription::find($sub);
        $this->assertSame($tgt, $fresh->package_ref);                 // upgrade committed
        $this->assertSame('ACTIVE', $fresh->status_code);
        // Charged to the wallet (not an invoice): balance 2000 - 1500 = 500.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'MONEY', 'balance' => 500.00]);
        $this->assertDatabaseHas('billing_intent', ['subscription_id' => $sub, 'intent_type' => 'PRORATION', 'settlement_channel' => 'WALLET', 'status' => 'CONFIRMED']);
        $this->assertDatabaseMissing('invoice', ['subscription_id' => $sub]); // no fee invoice raised
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionUpgraded']);
    }

    public function test_prepaid_upgrade_parks_until_topup_when_balance_short(): void
    {
        $src = $this->package('PKG_BASIC_Q', 1000);
        $tgt = $this->package('PKG_PREMIUM_Q', 2500); // +1500
        $sub = $this->prepaidSubscription($src);
        $this->fundWallet($sub, 500); // NOT enough for 1500

        $this->postJson("/api/subscriptions/{$sub}/upgrade", ['targetPackageRef' => $tgt], ['Idempotency-Key' => 'pu-2'])->assertStatus(202);
        $this->drain();

        // Parks in the transient awaiting the top-up; package not yet changed.
        $this->assertSame('PENDING_UPGRADE', Subscription::find($sub)->status_code);
        $this->assertSame($src, Subscription::find($sub)->package_ref);
        $this->assertDatabaseHas('billing_intent', ['subscription_id' => $sub, 'settlement_channel' => 'WALLET', 'status' => 'PENDING']);

        // Top up the wallet -> WalletToppedUp -> listener settles the intent + resumes.
        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 2000, 'walletCode' => 'MONEY'], ['Idempotency-Key' => 'tp-q'])->assertCreated();
        Artisan::call('sophix:outbox:dispatch');
        $this->drain(); // fulfilment -> commit

        $fresh = Subscription::find($sub);
        $this->assertSame($tgt, $fresh->package_ref);
        $this->assertSame('ACTIVE', $fresh->status_code);
        // 500 + 2000 - 1500 = 1000 left.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'MONEY', 'balance' => 1000.00]);
        $this->assertDatabaseHas('billing_intent', ['subscription_id' => $sub, 'status' => 'CONFIRMED']);
    }
}
