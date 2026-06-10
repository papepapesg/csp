<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Services\CycleCloseService;
use Modules\Billing\Services\MediationRatingService;
use Modules\Billing\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Catalog\Models\PackageVersion;
use Modules\Subscription\Models\Subscription;
use Tests\TestCase;

/**
 * BIL-03 cycle close: the per-subscription boundary engine that charges the
 * recurring package fee PLUS accumulated usage at cycle end, advances the
 * anchor idempotently, and freezes a prepaid cycle that can't pay until top-up.
 */
class CycleCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function pricedPackage(float $price): string
    {
        \Illuminate\Support\Facades\DB::table('package')->insertOrIgnore([
            'id' => 'pkg_home', 'operator_code' => 'WIK', 'code' => 'HOME', 'name' => 'Home',
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pv = PackageVersion::query()->create([
            'package_id' => 'pkg_home', 'price' => $price, 'currency' => 'KES',
            'effective_from' => now()->subYear(), 'status' => PackageVersion::STATUS_ACTIVE,
        ]);

        return $pv->getKey();
    }

    private function subscription(string $mode, ?string $packageVersionId, ?\Carbon\Carbon $cycleEnd = null): Subscription
    {
        return Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_home',
            'package_version_id' => $packageVersionId, 'status_code' => 'ACTIVE',
            'currency' => 'KES', 'billing_mode' => $mode, 'cycle_frequency_months' => 1,
            'current_cycle_start' => now()->subMonth(),
            'current_cycle_end' => $cycleEnd ?? now()->subMinute(), // already due
        ]);
    }

    private function rateUsage(string $sub, string $ref): void
    {
        $med = app(MediationRatingService::class);
        $med->ingest([['usage_type' => 'DATA', 'quantity' => 2000, 'source_ref' => $ref, 'subscription_id' => $sub]]); // 1000.00
        $med->ratePending('WIK');
    }

    public function test_postpaid_close_invoices_recurring_fee_plus_usage_and_advances_anchor(): void
    {
        $sub = $this->subscription('POSTPAID', $this->pricedPackage(2500));
        $this->rateUsage($sub->subscription_id, 'cdr-a');

        $r = app(CycleCloseService::class)->scan('WIK');
        $this->assertSame(1, $r['closed']);

        // One invoice = 2500 recurring + 1000 usage.
        $this->assertDatabaseHas('invoice', ['subscription_id' => $sub->subscription_id, 'total_amount' => 3500.00, 'status' => 'OPEN']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CycleClosed']);
        $this->assertDatabaseHas('rated_event', ['subscription_id' => $sub->subscription_id, 'billed' => true]);

        // Anchor advanced: closed window recorded, new end ~1 month out.
        $sub->refresh();
        $this->assertNotNull($sub->last_cycle_closed_window_end);
        $this->assertTrue($sub->current_cycle_end->isFuture());

        // Idempotent: a second pass finds nothing due.
        $this->assertSame(0, app(CycleCloseService::class)->scan('WIK')['closed']);
    }

    public function test_flat_rate_subscription_with_no_usage_still_bills_the_recurring_fee(): void
    {
        // The gap this closed: usage-only billing produced no invoice for a
        // flat-rate subscription. Now the recurring fee alone raises one.
        $sub = $this->subscription('POSTPAID', $this->pricedPackage(1999));

        app(CycleCloseService::class)->scan('WIK');

        $this->assertDatabaseHas('invoice', ['subscription_id' => $sub->subscription_id, 'total_amount' => 1999.00]);
    }

    public function test_prepaid_close_debits_wallet_then_freezes_on_shortfall_until_topup(): void
    {
        $this->seed(WalletCatalogSeeder::class);
        $sub = $this->subscription('PREPAID', $this->pricedPackage(1200));
        $wallets = app(WalletService::class);
        $wallet = $wallets->ensureWallet($sub->subscription_id, 'MONEY_KES', 'a1', 'c1');
        $wallets->credit($wallet, 500, 'TOPUP'); // short of 1200

        // Boundary can't be paid → frozen (no advance), CyclePaymentMissed.
        $this->assertSame(0, app(CycleCloseService::class)->scan('WIK')['closed']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CyclePaymentMissed']);
        $sub->refresh();
        $this->assertNull($sub->last_cycle_closed_window_end); // anchor frozen
        $this->assertDatabaseMissing('invoice', ['subscription_id' => $sub->subscription_id]);

        // Top-up triggers the unfreeze listener (R-BIL-03-W-4); cycle settles + advances.
        $wallets->credit($wallet->refresh(), 1000, 'TOPUP');
        $topup = \App\Foundation\Events\Outbox\OutboxEvent::query()
            ->where('event_type', 'WalletToppedUp')
            ->whereJsonContains('payload->subscriptionId', $sub->subscription_id)
            ->latest('id')->firstOrFail();
        app(\Modules\Billing\Listeners\RetryFrozenCycleOnTopup::class)
            ->handle(new \App\Foundation\Events\OutboxEventPublished($topup));

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CycleActivated']);
        $this->assertDatabaseHas('wallet', ['subscription_id' => $sub->subscription_id, 'balance' => 300.00]); // 1500 - 1200
        $sub->refresh();
        $this->assertNotNull($sub->last_cycle_closed_window_end);
    }

    public function test_run_is_audited(): void
    {
        $this->subscription('POSTPAID', $this->pricedPackage(100));
        $r = app(CycleCloseService::class)->scan('WIK');
        $this->assertDatabaseHas('cycle_close_run', ['run_id' => $r['run_id'], 'subscriptions_closed' => 1]);
    }
}
