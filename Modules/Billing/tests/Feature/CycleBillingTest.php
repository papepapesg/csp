<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\CycleBillingService;
use Modules\Billing\Mediation\Services\MediationRatingService;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

/**
 * BIL-02 cycle billing: unbilled rated events settle by billing mode at cycle close —
 * POSTPAID raises an invoice, PREPAID drains the wallet — then the events are flagged.
 */
class CycleBillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function subscription(string $billingMode): string
    {
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_1',
            'status_code' => 'ACTIVE', 'currency' => 'KES', 'billing_mode' => $billingMode,
        ]);

        return $sub->subscription_id;
    }

    /** Ingest + rate 2000 MB of DATA (flat 0.50/MB = 1000.00). */
    private function rateUsage(string $sub, string $ref): void
    {
        $med = app(MediationRatingService::class);
        $med->ingest([['usage_type' => 'DATA', 'quantity' => 2000, 'source_ref' => $ref, 'subscription_id' => $sub]]);
        $med->ratePending('WIK');
    }

    public function test_postpaid_cycle_billing_raises_an_invoice(): void
    {
        $sub = $this->subscription('POSTPAID');
        $this->rateUsage($sub, 'cdr-1');

        $r = app(CycleBillingService::class)->run('WIK');
        $this->assertSame(1, $r['billed']);

        $this->assertDatabaseHas('invoice', ['subscription_id' => $sub, 'account_id' => 'a1', 'total_amount' => 1000.00, 'status' => 'OPEN']);
        $this->assertDatabaseHas('rated_event', ['subscription_id' => $sub, 'billed' => true]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'SubscriptionCycleBilled']);
    }

    public function test_prepaid_cycle_billing_drains_the_wallet(): void
    {
        $this->seed(WalletCatalogSeeder::class);
        $sub = $this->subscription('PREPAID');
        $wallet = app(WalletService::class)->ensureWallet($sub, 'MONEY_KES', 'a1', 'c1');
        app(WalletService::class)->credit($wallet, 1500, 'TOPUP');
        $this->rateUsage($sub, 'cdr-2');

        $r = app(CycleBillingService::class)->run('WIK');
        $this->assertSame(1, $r['billed']);

        // 1500 - 1000 usage = 500; no invoice for a prepaid subscription.
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'wallet_code' => 'MONEY_KES', 'balance' => 500.00]);
        $this->assertDatabaseMissing('invoice', ['subscription_id' => $sub]);
        $this->assertDatabaseHas('rated_event', ['subscription_id' => $sub, 'billed' => true]);
    }

    public function test_prepaid_with_insufficient_balance_leaves_events_unbilled(): void
    {
        $this->seed(WalletCatalogSeeder::class);
        $sub = $this->subscription('PREPAID');
        $wallet = app(WalletService::class)->ensureWallet($sub, 'MONEY_KES', 'a1', 'c1');
        app(WalletService::class)->credit($wallet, 400, 'TOPUP'); // < 1000 usage
        $this->rateUsage($sub, 'cdr-3');

        $this->assertSame(0, app(CycleBillingService::class)->run('WIK')['billed']);
        $this->assertDatabaseHas('rated_event', ['subscription_id' => $sub, 'billed' => false]);
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub, 'balance' => 400.00]); // untouched
    }
}
