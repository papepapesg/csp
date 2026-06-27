<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Invoicing\Services\CycleCloseService;
use Modules\Billing\Mediation\Services\MediationRatingService;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Catalog\Plm\Models\PackageVersion;
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

    /** An ILM customer + account the snapshot service can resolve (R-GEN-01-F-6). */
    private function customer(string $customerId = 'c1', string $accountId = 'a1', string $language = 'en', string $type = 'RES'): void
    {
        \Modules\Ilm\Models\Customer::query()->firstOrCreate(['customer_id' => $customerId], [
            'operator_code' => 'WIK', 'type' => $type, 'name' => 'Jane Mwangi',
            'primary_msisdn' => '+254700000001', 'preferred_language' => $language, 'kyc_status' => 'APPROVED',
        ]);
        \Modules\Ilm\Models\CustomerAccount::query()->firstOrCreate(['account_id' => $accountId], [
            'account_number' => 'ACC-'.$accountId, 'customer_id' => $customerId, 'operator_code' => 'WIK',
            'service_address' => '12 Riverside Dr, Nairobi', 'status' => 'ACTIVE',
        ]);
    }

    private function subscription(string $mode, ?string $packageVersionId, ?\Carbon\Carbon $cycleEnd = null): Subscription
    {
        $this->customer();

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

    public function test_triple_play_wallet_grouping_splits_voice_invoice_with_itemized_call_detail(): void
    {
        // The triple-play scenario: Internet + TV bill together (the cyclical
        // package fee); VOICE is rated and — under a WALLET grouping policy —
        // gets its own invoice; the voice invoice has itemized call detail
        // (destination / when / duration) via the RAT-01 audit link.
        \Illuminate\Support\Facades\DB::table('invoice_grouping_config')->insert([
            'operator_code' => 'WIK', 'trigger_code' => 'CYCLE_POSTPAID',
            'grouping_dimension' => 'WALLET', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Catalog: package wallet = INTERNET (cyclical fee routes there); the
        // package's USAGE voice service routes to the VOICE wallet (PLM-CFG-01).
        \Illuminate\Support\Facades\DB::table('package')->insert([
            'id' => 'pkg_triple', 'operator_code' => 'WIK', 'code' => 'TRIPLE', 'name' => 'Triple Play',
            'status' => 'ACTIVE', 'default_wallet_ref' => 'WALLET_INTERNET', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('service_class')->insertOrIgnore([
            'id' => 'scl_voice', 'operator_code' => 'WIK', 'name' => 'Voice', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('service')->insert([
            'id' => 'svc_voice', 'operator_code' => 'WIK', 'code' => 'VOICE_LINE', 'name' => 'Voice line',
            'service_class_id' => 'scl_voice', 'consumption_model' => 'USAGE', 'revenue_category' => 'VOICE',
            'default_wallet_ref' => 'WALLET_VOICE', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('package_service')->insert([
            'id' => 'pks_1', 'package_id' => 'pkg_triple', 'service_id' => 'svc_voice', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pv = PackageVersion::query()->create([
            'package_id' => 'pkg_triple', 'price' => 4500, 'currency' => 'KES',
            'effective_from' => now()->subYear(), 'status' => PackageVersion::STATUS_ACTIVE,
        ]);
        $this->customer();
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_triple',
            'package_version_id' => $pv->getKey(), 'status_code' => 'ACTIVE',
            'currency' => 'KES', 'billing_mode' => 'POSTPAID', 'cycle_frequency_months' => 1,
            'current_cycle_start' => now()->subMonth(), 'current_cycle_end' => now()->subMinute(),
        ]);

        // Two rated voice calls (CDRs with destination + duration).
        $med = app(MediationRatingService::class);
        $med->ingest([
            ['usage_type' => 'VOICE', 'quantity' => 180, 'destination' => 'ONNET', 'source_ref' => 'call-1', 'subscription_id' => $sub->subscription_id],
            ['usage_type' => 'VOICE', 'quantity' => 60, 'destination' => 'INTL_UG', 'source_ref' => 'call-2', 'subscription_id' => $sub->subscription_id],
        ]);
        $med->ratePending('WIK');

        app(CycleCloseService::class)->scan('WIK');

        // TWO invoices: the cyclical fee (Internet+TV wallet) and voice (own wallet).
        $invoices = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->get();
        $this->assertCount(2, $invoices);
        $internet = $invoices->firstWhere('grouping_key_values', 'WALLET_INTERNET');
        $voice = $invoices->firstWhere('grouping_key_values', 'WALLET_VOICE');
        $this->assertEquals(4500.00, $internet->total_amount); // cyclical package fee together
        $this->assertSame('SUBSCRIPTION', $internet->lines()->where('line_type', 'DETAIL')->first()->service_category_code);
        $this->assertSame('VOICE', $voice->lines()->where('line_type', 'DETAIL')->first()->service_category_code);

        // RAT-01 mark-invoiced: the calls are linked to the VOICE invoice…
        $this->assertSame(2, \Modules\Billing\Mediation\Models\RatedEvent::query()->where('invoice_id', $voice->invoice_id)->count());

        // …and the itemized page lists each call: destination, when, duration.
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $user = \App\Models\User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('BILLING_OPERATOR');
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $items = $this->getJson('/api/rated-events?invoice_id='.$voice->invoice_id)->assertOk()->json('items');
        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing(['ONNET', 'INTL_UG'], array_column($items, 'destination'));
        $this->assertNotNull($items[0]['occurred_at']);
        $this->assertContains(180, array_map(fn ($i) => (int) $i['quantity'], $items)); // duration seconds
    }

    public function test_postpaid_close_invoices_recurring_fee_plus_usage_and_advances_anchor(): void
    {
        $sub = $this->subscription('POSTPAID', $this->pricedPackage(2500));
        $this->rateUsage($sub->subscription_id, 'cdr-a'); // DATA 1000

        $r = app(CycleCloseService::class)->scan('WIK');
        $this->assertSame(1, $r['closed']);

        // One invoice (SINGLE policy) = 2500 recurring + 1000 usage.
        $invoice = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->firstOrFail();
        $this->assertEquals(3500.00, $invoice->total_amount);
        $this->assertSame('SINGLE', $invoice->grouping_dimension);

        // SUMMARY/DETAIL structure: a package SUMMARY with the subscription fee and
        // the usage as DETAIL leaves — distinguished by service_category_code
        // (the DD's discriminator), not a charge_type field.
        $summary = $invoice->lines()->where('line_type', 'SUMMARY')->firstOrFail();
        $details = $invoice->lines()->where('line_type', 'DETAIL')->get();
        $this->assertEqualsCanonicalizing(['SUBSCRIPTION', 'DATA'], $details->pluck('service_category_code')->all());
        $this->assertTrue($details->every(fn ($d) => $d->parent_summary_line_id === $summary->id));
        $this->assertEquals(2500.00, $details->firstWhere('service_category_code', 'SUBSCRIPTION')->subtotal);
        $this->assertEquals(1000.00, $details->firstWhere('service_category_code', 'DATA')->subtotal);

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CycleClosed']);
        $this->assertDatabaseHas('rated_event', ['subscription_id' => $sub->subscription_id, 'billed' => true]);

        // Anchor advanced: closed window recorded, new end ~1 month out.
        $sub->refresh();
        $this->assertNotNull($sub->last_cycle_closed_window_end);
        $this->assertTrue($sub->current_cycle_end->isFuture());

        // Idempotent: a second pass finds nothing due.
        $this->assertSame(0, app(CycleCloseService::class)->scan('WIK')['closed']);
    }

    public function test_voice_and_data_usage_become_distinct_service_category_charges(): void
    {
        // The user's point: usage (voice/data) is a different billing model than
        // the flat cycle fee, and each usage category is its own line — carried by
        // service_category_code per the DD charge schema.
        $sub = $this->subscription('POSTPAID', $this->pricedPackage(1000));
        $med = app(MediationRatingService::class);
        $med->ingest([
            ['usage_type' => 'DATA', 'quantity' => 1000, 'source_ref' => 'd1', 'subscription_id' => $sub->subscription_id],
            ['usage_type' => 'VOICE', 'quantity' => 120, 'destination' => 'ONNET', 'source_ref' => 'v1', 'subscription_id' => $sub->subscription_id],
        ]);
        $med->ratePending('WIK');

        app(CycleCloseService::class)->scan('WIK');
        $invoice = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->firstOrFail();
        $categories = $invoice->lines()->where('line_type', 'DETAIL')->pluck('service_category_code')->all();

        // Subscription fee + DATA usage + VOICE usage = three distinct detail leaves.
        $this->assertContains('SUBSCRIPTION', $categories);
        $this->assertContains('DATA', $categories);
        $this->assertContains('VOICE', $categories);
    }

    public function test_per_line_tax_is_computed_and_aggregated_into_tax_summary(): void
    {
        // R-GEN-01-L-2: when the operator taxes the charge's category, the line
        // carries tax (tax_breakdown) and the invoice aggregates tax_summary.
        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->seed(\Modules\Catalog\Database\Seeders\TaxCatalogSeeder::class);
        // Map the SUBSCRIPTION category to the seeded WIK_INTERNET tax group.
        \Modules\Rules\Models\DecisionTable::query()->create([
            'table_id' => \App\Foundation\Support\Id::make('dt'),
            'rule_set' => 'rules.tax-applicability', 'operator_code' => 'WIK', 'version' => 2,
            'name' => 'WIK tax applicability (test)', 'hit_policy' => 'FIRST',
            'rules' => [['ruleId' => 'R-T-1', 'when' => [['var' => 'taxableKind', 'op' => 'eq', 'value' => 'SUBSCRIPTION']], 'then' => ['taxGroup' => 'WIK_INTERNET']]],
            'default_output' => ['taxGroup' => null], 'status' => 'DEPLOYED',
        ]);

        $sub = $this->subscription('POSTPAID', $this->pricedPackage(1000));
        app(CycleCloseService::class)->scan('WIK');

        $invoice = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->firstOrFail();
        $recurring = $invoice->lines()->where('service_category_code', 'SUBSCRIPTION')->firstOrFail();
        $this->assertGreaterThan(0, (float) $recurring->tax_amount);
        $this->assertNotEmpty($recurring->tax_breakdown);
        $this->assertGreaterThan(0, (float) $invoice->tax_amount_total);
        $this->assertNotEmpty($invoice->tax_summary);
        $this->assertEquals(round($invoice->subtotal_amount + $invoice->tax_amount_total, 2), (float) $invoice->total_amount);
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
        app(\Modules\Billing\Invoicing\Listeners\RetryFrozenCycleOnTopup::class)
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

    public function test_partial_first_cycle_prorates_the_recurring_fee(): void
    {
        // A half-length first cycle (15 of 30 days) charges half the package fee.
        $this->customer();
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_home',
            'package_version_id' => $this->pricedPackage(3000), 'status_code' => 'ACTIVE',
            'currency' => 'KES', 'billing_mode' => 'POSTPAID', 'cycle_period_days' => 30,
            'current_cycle_start' => now()->subDays(15), 'current_cycle_end' => now()->subMinute(),
        ]);

        app(CycleCloseService::class)->scan('WIK');
        $invoice = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->firstOrFail();
        // 15/30 of 3000 = 1500.
        $this->assertEquals(1500.00, $invoice->lines()->where('service_category_code', 'SUBSCRIPTION')->first()->subtotal);
    }

    public function test_invoice_captures_an_immutable_customer_snapshot_that_drives_language(): void
    {
        // R-GEN-01-F-6: snapshot frozen at generation; R-GEN-01-L-5: line wording in
        // the CUSTOMER's language, not the operator default.
        \App\Foundation\Models\UiTranslation::query()->create([
            'operator_code' => '*', 'locale' => 'sw', 'domain' => 'BILLING', 'section' => 'charge',
            'key' => 'billing.charge.recurring', 'value' => 'Ada ya mwezi',
        ]);
        $this->customer('c1', 'a1', language: 'sw', type: 'COM');
        $sub = $this->subscription('POSTPAID', $this->pricedPackage(2000));

        app(CycleCloseService::class)->scan('WIK');
        $invoice = \Modules\Billing\Invoicing\Models\Invoice::query()->where('subscription_id', $sub->subscription_id)->firstOrFail();

        // Snapshot frozen on the invoice.
        $snap = $invoice->customer_snapshot;
        $this->assertSame('Jane Mwangi', $snap['name']);
        $this->assertSame('BUSINESS', $snap['customerCategory']);          // COM → BUSINESS
        $this->assertSame('sw', $snap['preferredLanguage']);
        $this->assertSame('12 Riverside Dr, Nairobi', $snap['billingAddress']);
        $this->assertNotEmpty($snap['capturedAt']);

        // Line description resolved in the customer's language.
        $this->assertSame('Ada ya mwezi', $invoice->lines()->where('service_category_code', 'SUBSCRIPTION')->first()->description);

        // Immutable: changing the customer master does NOT alter the stored snapshot.
        \Modules\Ilm\Models\Customer::query()->where('customer_id', 'c1')->update(['name' => 'Renamed Co', 'preferred_language' => 'en']);
        $this->assertSame('Jane Mwangi', $invoice->fresh()->customer_snapshot['name']);
    }

    public function test_missing_customer_fails_the_close_without_writing_a_partial_invoice(): void
    {
        // R-GEN-01-F-6: a snapshot fetch failure must NOT write an invoice.
        $sub = Subscription::query()->create([
            'subscription_id' => Id::make('sub'), 'customer_id' => 'ghost', 'account_id' => 'a1',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'pkg_home',
            'package_version_id' => $this->pricedPackage(500), 'status_code' => 'ACTIVE',
            'currency' => 'KES', 'billing_mode' => 'POSTPAID', 'cycle_frequency_months' => 1,
            'current_cycle_start' => now()->subMonth(), 'current_cycle_end' => now()->subMinute(),
        ]);

        $r = app(CycleCloseService::class)->scan('WIK');
        $this->assertSame(1, $r['failed']);
        $this->assertDatabaseMissing('invoice', ['subscription_id' => $sub->subscription_id]);
        $sub->refresh();
        $this->assertNull($sub->last_cycle_closed_window_end); // not advanced
    }
}
