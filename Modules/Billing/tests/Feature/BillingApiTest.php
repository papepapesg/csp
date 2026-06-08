<?php

namespace Modules\Billing\Tests\Feature;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(WalletCatalogSeeder::class); // PLM-CFG-03 wallet catalog (MONEY_KES, VOICE_KES, …)
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('BILLING_LEAD');
        Sanctum::actingAs($user);
    }

    private function makeInvoice(float $unit = 4999.0): array
    {
        return $this->postJson('/api/invoices', [
            'account_id' => 'acct_1',
            'lines' => [
                ['description' => 'Monthly fiber', 'quantity' => 1, 'unit_price' => $unit, 'tax_amount' => 0],
            ],
        ])->assertCreated()->json();
    }

    public function test_generate_invoice_computes_totals_and_legal_number(): void
    {
        $invoice = $this->makeInvoice();

        $this->assertSame('4999.00', $invoice['total_amount']);
        $this->assertSame('4999.00', $invoice['amount_due']);
        $this->assertSame('OPEN', $invoice['status']);
        $this->assertStringContainsString('Inv-WIK-', $invoice['legal_invoice_number']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'InvoiceGenerated']);
    }

    public function test_payment_fully_pays_invoice(): void
    {
        $invoice = $this->makeInvoice(1000.0);

        $this->postJson('/api/payments', [
            'account_id' => 'acct_1',
            'paid_amount' => 1000.0,
            'method' => 'MPESA',
        ], ['Idempotency-Key' => 'pay-1'])->assertCreated();

        $this->getJson("/api/invoices/{$invoice['invoice_id']}")
            ->assertOk()
            ->assertJsonPath('status', 'PAID')
            ->assertJsonPath('amount_due', '0.00');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'InvoicePaid']);
    }

    public function test_overpayment_creates_account_credit(): void
    {
        $this->makeInvoice(1000.0);

        $this->postJson('/api/payments', [
            'account_id' => 'acct_1',
            'paid_amount' => 1500.0,
            'method' => 'MPESA',
        ], ['Idempotency-Key' => 'pay-2'])->assertCreated();

        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acct_1', 'balance' => 500.00]);
    }

    public function test_wallet_topup_and_debit_with_insufficient_funds_guard(): void
    {
        $sub = 'sub_prepaid_1';

        $this->getJson("/api/wallets/{$sub}/balance")->assertOk()->assertJsonPath('balance', '0.00');

        $this->postJson("/api/wallets/{$sub}/topup", ['amount' => 200], ['Idempotency-Key' => 'tp-1'])
            ->assertCreated()
            ->assertJsonPath('balance_after', '200.00');

        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 50, 'reason' => 'CYCLE_CHARGE'])
            ->assertOk()
            ->assertJsonPath('balance_after', '150.00');

        $this->postJson("/api/wallets/{$sub}/debit", ['amount' => 999])
            ->assertStatus(422)
            ->assertJsonPath('errorCode', 'WALLET_INSUFFICIENT_FUNDS');

        $this->assertDatabaseHas('outbox_events', ['event_type' => 'WalletToppedUp']);
    }

    public function test_payment_requires_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('FIELD_TECHNICIAN');
        Sanctum::actingAs($user);

        $this->postJson('/api/payments', ['account_id' => 'a', 'paid_amount' => 1, 'method' => 'MPESA'])
            ->assertForbidden();
    }

    public function test_issue_tax_invoice_fiscalises_via_gateway(): void
    {
        $invoice = $this->makeInvoice(2000.0);

        $this->postJson("/api/invoices/{$invoice['invoice_id']}/tax-invoice", [], ['Idempotency-Key' => 'tax-1'])
            ->assertCreated()
            ->assertJsonPath('status', 'FISCALISED');

        $this->assertDatabaseHas('tax_invoice', ['invoice_id' => $invoice['invoice_id'], 'status' => 'FISCALISED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'TaxInvoiceIssued']);
    }
}
