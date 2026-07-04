<?php

namespace Modules\Billing\Tax\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Tax\Database\Seeders\TaxConfigSeeder;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Tax\Models\TaxInvoice;
use Modules\Billing\Tax\Models\TaxOperatorConfig;
use Modules\Billing\Tax\Services\TaxInvoiceGenerator;
use Modules\Billing\Tax\Services\TaxSigningService;
use Modules\Ilm\Models\Customer;
use Tests\TestCase;

/**
 * BIL-02-TAX-01 tax invoice — the legal fiscal document, triggered by a PAYMENT (not by
 * billing) and signed asynchronously by the operator's tax-authority gateway. Covers:
 * proportional postpaid generation + tax-inclusive prepaid decomposition, per-operator
 * enablement, the async signing state machine (GENERATED → PENDING_SIGNATURE → SIGNED /
 * SIGNING_FAILED → GAVE_UP_AUTO), transient retry-then-give-up vs validation parking, and
 * dual-controlled cancellation of a signed invoice.
 */
class Tax01Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(TaxConfigSeeder::class);
    }

    private function generator(): TaxInvoiceGenerator
    {
        return app(TaxInvoiceGenerator::class);
    }

    private function signing(): TaxSigningService
    {
        return app(TaxSigningService::class);
    }

    private function customer(string $id, ?string $pin = null): Customer
    {
        return Customer::query()->create([
            'customer_id' => $id, 'operator_code' => 'WIK', 'name' => 'Test', 'type' => 'RES',
            'primary_msisdn' => '+254712345678', 'tax_identifier' => $pin,
        ]);
    }

    private function postpaidInvoice(float $total = 1000, float $tax = 160): Invoice
    {
        return Invoice::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acct_1', 'customer_id' => 'cust_1', 'currency' => 'KES',
            'status' => Invoice::OPEN, 'issue_date' => now(), 'due_date' => now()->addDays(7),
            'subtotal_amount' => $total - $tax, 'total_amount' => $total, 'amount_due' => $total,
            'tax_summary' => ['totalTaxAmount' => $tax, 'serviceCategory' => 'INTERNET'],
        ]);
    }

    /**
     * EXPECTATION — tax follows the money, proportionally (postpaid).
     * A tax invoice is triggered by a PAYMENT, not by billing — paying half a 1,000
     * invoice generates a tax invoice for exactly half the base AND half the tax
     * (80 of 160), with its own TAX- legal number, linked to the source invoice.
     */
    public function test_postpaid_partial_payment_generates_proportional_tax_invoice(): void
    {
        $this->customer('cust_1');
        $invoice = $this->postpaidInvoice(1000, 160);

        // Pay half → tax invoice covers 50% of base + tax.
        $tax = $this->generator()->fromPaymentApplied($invoice, 'pay_1', 500.0, 'evt_1');

        $this->assertSame(TaxInvoice::GENERATED, $tax->status);
        $this->assertSame('500.00', $tax->total_amount);
        $this->assertSame('80.00', $tax->tax_total);   // 160 * 0.5
        $this->assertSame('420.00', $tax->subtotal_amount); // 840 * 0.5
        $this->assertSame($invoice->invoice_id, $tax->original_invoice_id);
        $this->assertStringStartsWith('TAX-WIK-', $tax->legal_invoice_number);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TaxInvoiceIssued']);
    }

    /**
     * EXPECTATION — one tax invoice per payment, replay-safe (T-5).
     * Two partial payments produce two distinct tax invoices; replaying the same
     * payment event returns the SAME tax invoice — idempotent on the payment.
     */
    public function test_each_partial_payment_makes_its_own_tax_invoice_and_is_idempotent(): void
    {
        $this->customer('cust_1');
        $invoice = $this->postpaidInvoice();

        $a = $this->generator()->fromPaymentApplied($invoice, 'pay_1', 400.0, 'evt_1');
        $b = $this->generator()->fromPaymentApplied($invoice, 'pay_2', 600.0, 'evt_2');
        $again = $this->generator()->fromPaymentApplied($invoice, 'pay_1', 400.0, 'evt_1'); // replay

        $this->assertNotSame($a->tax_invoice_id, $b->tax_invoice_id);
        $this->assertSame($a->tax_invoice_id, $again->tax_invoice_id); // T-5 idempotency
        $this->assertSame(2, TaxInvoice::count());
    }

    /**
     * EXPECTATION — tax invoicing is per-operator config (T-4).
     * An operator with tax disabled generates no tax invoice at all — the feature is
     * opt-in, so operators without a tax authority simply never produce one.
     */
    public function test_disabled_operator_generates_nothing(): void
    {
        TaxOperatorConfig::query()->where('operator_code', 'WIK')->update(['enabled' => false]);
        $this->customer('cust_1');
        $invoice = $this->postpaidInvoice();

        $this->assertNull($this->generator()->fromPaymentApplied($invoice, 'pay_1', 500.0, 'evt_1'));
        $this->assertSame(0, TaxInvoice::count());
    }

    /**
     * EXPECTATION — prepaid top-ups are tax-INCLUSIVE and decomposed.
     * A 1,160 wallet top-up (no source invoice) yields a PREPAID tax invoice whose
     * base + tax sum back to the inclusive 1,160, with a non-zero tax extracted.
     */
    public function test_prepaid_wallet_topup_decomposes_inclusive_total(): void
    {
        $this->seed(\Modules\Catalog\Database\Seeders\TaxCatalogSeeder::class);
        $this->customer('cust_2');

        $tax = $this->generator()->fromWalletTopup([
            'operatorCode' => 'WIK', 'eventId' => 'topup_1', 'customerId' => 'cust_2',
            'walletTypeCode' => 'DATA', 'serviceCategoryCode' => 'INTERNET', 'topupAmount' => 1160.0, 'currency' => 'KES',
        ]);

        $this->assertSame('PREPAID', $tax->billing_mode);
        $this->assertNull($tax->original_invoice_id);
        // Inclusive decomposition: base + tax == the tax-inclusive topup, with a non-zero tax.
        $this->assertSame('1160.00', $tax->total_amount);
        $this->assertEqualsWithDelta(1160.0, (float) $tax->subtotal_amount + (float) $tax->tax_total, 0.01);
        $this->assertGreaterThan(0, (float) $tax->tax_total);
        $this->assertSame('topup_1', $tax->metadata['topup_event_id']);
    }

    /**
     * EXPECTATION — signing is async and authority-driven.
     * A GENERATED tax invoice, signed, moves to SIGNED with the authority's signed
     * number and timestamp, and emits TaxInvoiceSigned.
     */
    public function test_signing_happy_path_signs_and_emits(): void
    {
        $this->customer('cust_1');
        $tax = $this->generator()->fromPaymentApplied($this->postpaidInvoice(), 'pay_1', 1000.0, 'evt_1');

        $signed = $this->signing()->sign($tax);

        $this->assertSame(TaxInvoice::SIGNED, $signed->status);
        $this->assertStringStartsWith('SIG-', $signed->signed_invoice_number);
        $this->assertNotNull($signed->signed_at);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TaxInvoiceSigned']);
    }

    /**
     * EXPECTATION — transient signing failures retry on backoff, then give up (F-2).
     * A gateway timeout parks SIGNING_FAILED with a scheduled next_retry_at; the
     * retry scanner re-submits, and after the 8-retry budget it flips to
     * GAVE_UP_AUTO and emits TaxInvoiceSigningGaveUp.
     */
    public function test_transient_failure_retries_then_gives_up(): void
    {
        $this->customer('cust_t', 'TIMEOUT'); // signer throws TRANSIENT GATEWAY_TIMEOUT
        $invoice = $this->postpaidInvoice();
        $invoice->update(['customer_id' => 'cust_t']);
        $tax = $this->generator()->fromPaymentApplied($invoice, 'pay_t', 1000.0, 'evt_t');

        $this->signing()->sign($tax);
        $tax->refresh();
        $this->assertSame(TaxInvoice::SIGNING_FAILED, $tax->status);
        $this->assertSame('GATEWAY_TIMEOUT', $tax->signing_failure_type);
        $this->assertNotNull($tax->next_retry_at);

        // Exhaust the 8-retry budget via the retry scanner.
        for ($i = 0; $i < 9; $i++) {
            TaxInvoice::query()->whereKey($tax->tax_invoice_id)->update(['next_retry_at' => now()->subMinute()]);
            $this->signing()->retryScan('WIK');
        }
        $tax->refresh();
        $this->assertSame(TaxInvoice::GAVE_UP_AUTO, $tax->status);
        $this->assertGreaterThanOrEqual(8, $tax->failures()->count());
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TaxInvoiceSigningGaveUp']);
    }

    /**
     * EXPECTATION — validation failures park for a human, no auto-retry (F-3).
     * A validation (not transient) failure sets SIGNING_FAILED with NO next_retry_at
     * and does not consume the retry budget — the scanner skips it; an admin resolves.
     */
    public function test_validation_failure_parks_for_admin_no_auto_retry(): void
    {
        $this->customer('cust_v', 'INVALID'); // VALIDATION failure
        $invoice = $this->postpaidInvoice();
        $invoice->update(['customer_id' => 'cust_v']);
        $tax = $this->generator()->fromPaymentApplied($invoice, 'pay_v', 1000.0, 'evt_v');

        $this->signing()->sign($tax);
        $tax->refresh();
        $this->assertSame(TaxInvoice::SIGNING_FAILED, $tax->status);
        $this->assertSame('VALIDATION_FAILURE', $tax->signing_failure_type);
        $this->assertNull($tax->next_retry_at);  // no auto-retry (F-3)
        $this->assertSame(0, $tax->retry_count);  // retry budget not consumed
        $this->assertSame(0, $this->signing()->retryScan('WIK')); // scanner skips it
    }

    /**
     * EXPECTATION — cancelling a SIGNED tax invoice is dual-controlled (C-1/C-2).
     * An unsigned tax invoice cancels immediately with no gateway call. A signed one
     * only stages the cancellation; it takes a DIFFERENT user holding tax.compliance
     * to approve, which then calls the gateway and records the authority reference.
     */
    public function test_unsigned_cancel_is_immediate_signed_cancel_needs_dual_approval(): void
    {
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('BILLING_LEAD');
        Sanctum::actingAs($admin);
        $this->customer('cust_1');

        // Unsigned → cancels immediately, no gateway call.
        $gen = $this->generator()->fromPaymentApplied($this->postpaidInvoice(), 'pay_1', 1000.0, 'evt_1');
        $this->postJson("/api/tax-invoices/{$gen->tax_invoice_id}/cancel", ['reason_code' => 'DUPLICATE'])
            ->assertOk()->assertJsonPath('status', 'CANCELLED');

        // Signed → cancellation is staged; approval requires the compliance role + a different user.
        $signed = $this->signing()->sign($this->generator()->fromPaymentApplied($this->postpaidInvoice(), 'pay_2', 1000.0, 'evt_2'));
        $this->postJson("/api/tax-invoices/{$signed->tax_invoice_id}/cancel", ['reason_code' => 'ERROR'])
            ->assertOk()->assertJsonPath('cancellationPending', true);
        $this->assertSame('SIGNED', $signed->refresh()->status); // not yet cancelled

        // A billing lead lacks tax.compliance → forbidden.
        $this->postJson("/api/tax-invoices/{$signed->tax_invoice_id}/cancel/approve")->assertForbidden();

        // A compliance officer (different user) approves → gateway cancel + CANCELLED.
        $officer = User::factory()->create(['operator_code' => 'WIK']);
        $officer->assignRole('TAX_COMPLIANCE_OFFICER');
        Sanctum::actingAs($officer);
        $this->postJson("/api/tax-invoices/{$signed->tax_invoice_id}/cancel/approve")
            ->assertOk()->assertJsonPath('status', 'CANCELLED');
        $this->assertNotNull($signed->refresh()->cancellation_reference);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TaxInvoiceCancelled']);
    }

    /**
     * EXPECTATION — the ops surfaces read back the state.
     * The dashboard counts a signed invoice, and per-invoice signing-history returns
     * its current status.
     */
    public function test_dashboard_and_signing_history(): void
    {
        $admin = User::factory()->create(['operator_code' => 'WIK']);
        $admin->assignRole('BILLING_LEAD');
        Sanctum::actingAs($admin);
        $this->customer('cust_1');
        $signed = $this->signing()->sign($this->generator()->fromPaymentApplied($this->postpaidInvoice(), 'pay_1', 1000.0, 'evt_1'));

        $this->getJson('/api/tax-invoices/dashboard')->assertOk()->assertJsonPath('signed', 1);
        $this->getJson("/api/tax-invoices/{$signed->tax_invoice_id}/signing-history")->assertOk()
            ->assertJsonPath('status', 'SIGNED');
    }

    /**
     * EXPECTATION — a PaymentApplied event drives tax generation end to end.
     * The event bridge, handed a PaymentApplied outbox event, generates the tax
     * invoice for that payment with the PAYMENT_APPLIED trigger recorded.
     */
    public function test_payment_event_triggers_tax_invoice_via_bridge(): void
    {
        $this->customer('cust_1');
        $invoice = $this->postpaidInvoice();
        $event = new \App\Foundation\Events\Outbox\OutboxEvent;
        $event->setRawAttributes([
            'event_type' => 'PaymentApplied', 'operator_code' => 'WIK',
            'payload' => json_encode(['invoiceId' => $invoice->invoice_id, 'paymentId' => 'pay_b', 'applied' => '1000', 'customerId' => 'cust_1']),
        ]);

        app(\Modules\Billing\Tax\Listeners\TaxEventBridge::class)->handle(new \App\Foundation\Events\OutboxEventPublished($event));

        $this->assertDatabaseHas('tax_invoice', ['original_invoice_id' => $invoice->invoice_id, 'triggering_event_type' => 'PAYMENT_APPLIED']);
    }
}
