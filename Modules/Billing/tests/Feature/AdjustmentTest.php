<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Database\Seeders\AdjustmentConfigSeeder;
use Modules\Billing\Models\AdjustmentRequest;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Services\InvoiceService;
use Modules\Billing\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

/**
 * BIL-02-ADJ-01 + BIL-01-CN-01: the governed adjustment pipeline — proposal,
 * approval, note issuance and application across POSTPAID invoices (with
 * surplus to the account credit balance) and PREPAID wallets (insufficient
 * balance fails, /retry-application after top-up succeeds).
 */
class AdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(AdjustmentConfigSeeder::class);
        $this->seed(WalletCatalogSeeder::class);
        $user = User::factory()->create(['operator_code' => 'WIK']);
        $user->assignRole('BILLING_LEAD'); // adjustment.create + adjustment.approve
        Sanctum::actingAs($user);
        Context::setOperatorCode('WIK');
    }

    private function postpaidInvoice(float $amount = 2500, string $account = 'acc_1', string $customer = 'cust_1'): Invoice
    {
        return app(InvoiceService::class)->generate(
            ['account_id' => $account, 'customer_id' => $customer],
            [['description' => 'Monthly fee', 'quantity' => 1, 'unit_price' => $amount]],
        );
    }

    public function test_full_credit_adjustment_issues_credit_note_and_clears_parent_outstanding(): void
    {
        $invoice = $this->postpaidInvoice(2500);

        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'DISPUTE_RESOLVED',
            'justification' => 'Dispute resolved in customer favor',
        ], ['Idempotency-Key' => 'adj-full-1'])->assertCreated();

        $adjustmentId = $res->json('adjustment_id');
        $this->assertSame('PENDING_APPROVAL', $res->json('status'));
        $this->assertSame('2500.00', $res->json('amount')); // FULL = parent total

        // Approve (single-step policy) → note issued + applied in one go.
        $approved = $this->postJson("/api/adjustments/{$adjustmentId}/approve", ['comment' => 'ok'])->assertOk();
        $this->assertSame('APPLIED', $approved->json('status'));
        $noteId = $approved->json('note_invoice_id');

        // The note is a CREDIT_NOTE invoice with its own CN legal number.
        $this->assertDatabaseHas('invoice', ['invoice_id' => $noteId, 'type' => 'CREDIT_NOTE', 'original_invoice_id' => $invoice->invoice_id, 'status' => 'ISSUED']);
        $this->assertStringStartsWith('CN-WIK-', Invoice::query()->find($noteId)->legal_invoice_number);

        // Parent outstanding cleared; one ledger row, fully applied.
        $this->assertDatabaseHas('invoice', ['invoice_id' => $invoice->invoice_id, 'amount_due' => 0.00, 'status' => 'PAID']);
        $this->assertDatabaseHas('note_application_ledger', ['note_id' => $noteId, 'target_kind' => 'INVOICE', 'target_id' => $invoice->invoice_id, 'applied_amount' => 2500.00, 'status' => 'APPLIED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteIssued']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteApplied']);
    }

    public function test_credit_note_surplus_goes_to_account_credit_balance(): void
    {
        $invoice = $this->postpaidInvoice(1000, 'acc_surplus', 'cust_surplus');

        // AMOUNT-scope credit above the outstanding: 1500 against 1000 due.
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'AMOUNT', 'amount' => 1500,
            'parent_invoice_id' => $invoice->invoice_id,
            'service_category_code' => 'SLA_COMPENSATION',
            'reason_code' => 'SLA_COMPENSATION',
        ], ['Idempotency-Key' => 'adj-surplus-1'])->assertCreated();

        $this->postJson('/api/adjustments/'.$res->json('adjustment_id').'/approve')->assertOk();

        // Invoice cleared (1000) + surplus 500 on the account credit balance: TWO ledger rows.
        $noteId = AdjustmentRequest::query()->find($res->json('adjustment_id'))->note_invoice_id;
        $this->assertDatabaseHas('note_application_ledger', ['note_id' => $noteId, 'target_kind' => 'INVOICE', 'applied_amount' => 1000.00]);
        $this->assertDatabaseHas('note_application_ledger', ['note_id' => $noteId, 'target_kind' => 'CREDIT_BALANCE', 'applied_amount' => 500.00]);
        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acc_surplus', 'balance' => 500.00]);
    }

    public function test_debit_note_reopens_a_paid_invoice(): void
    {
        $invoice = $this->postpaidInvoice(800, 'acc_debit', 'cust_debit');
        $invoice->update(['amount_due' => 0, 'amount_paid' => 800, 'status' => Invoice::PAID]);

        // 600 is above the auto-approve threshold (500): explicit approval required.
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'DEBIT', 'scope' => 'AMOUNT', 'amount' => 600,
            'parent_invoice_id' => $invoice->invoice_id,
            'service_category_code' => 'LATE_FEE',
            'reason_code' => 'LATE_FEE',
        ], ['Idempotency-Key' => 'adj-debit-1'])->assertCreated()->assertJsonPath('status', 'PENDING_APPROVAL');

        $this->postJson('/api/adjustments/'.$res->json('adjustment_id').'/approve')->assertOk();

        // Outstanding grows; the PAID invoice becomes payable again (R-CN-01-AP-2).
        $this->assertDatabaseHas('invoice', ['invoice_id' => $invoice->invoice_id, 'amount_due' => 600.00, 'status' => 'PARTIALLY_PAID']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteIssued']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteApplied']);
    }

    public function test_prepaid_credit_note_credits_the_wallet(): void
    {
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'AMOUNT', 'amount' => 600,
            'billing_mode' => 'PREPAID', 'subscription_id' => 'sub_prepaid_adj',
            'customer_id' => 'cust_pp', 'account_id' => 'acc_pp',
            'service_category_code' => 'GOODWILL',
            'reason_code' => 'GOODWILL_CREDIT',
        ], ['Idempotency-Key' => 'adj-prepaid-credit'])->assertCreated();

        $this->postJson('/api/adjustments/'.$res->json('adjustment_id').'/approve')->assertOk()
            ->assertJsonPath('status', 'APPLIED');

        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_adj', 'wallet_code' => 'MONEY_KES', 'balance' => 600.00]);
        $this->assertDatabaseHas('note_application_ledger', ['target_kind' => 'WALLET', 'target_id' => 'MONEY_KES', 'applied_amount' => 600.00, 'status' => 'APPLIED']);
    }

    public function test_prepaid_debit_note_fails_on_insufficient_wallet_then_retry_succeeds_after_topup(): void
    {
        $wallets = app(WalletService::class);
        $wallet = $wallets->ensureWallet('sub_prepaid_debit', 'MONEY_KES');
        $wallets->credit($wallet, 300, 'TOPUP');

        $res = $this->postJson('/api/adjustments', [
            'direction' => 'DEBIT', 'scope' => 'AMOUNT', 'amount' => 1000,
            'billing_mode' => 'PREPAID', 'subscription_id' => 'sub_prepaid_debit',
            'customer_id' => 'cust_ppd', 'account_id' => 'acc_ppd',
            'service_category_code' => 'CORRECTION',
            'reason_code' => 'OVER_CREDIT_RECOVERY',
        ], ['Idempotency-Key' => 'adj-prepaid-debit'])->assertCreated();
        $adjustmentId = $res->json('adjustment_id');

        // The wallet only holds 300: application FAILS, wallet never goes negative.
        $this->postJson("/api/adjustments/{$adjustmentId}/approve")->assertOk()
            ->assertJsonPath('status', 'APPLICATION_FAILED')
            ->assertJsonPath('failure_reason', 'WALLET_INSUFFICIENT_BALANCE');
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_debit', 'balance' => 300.00]);
        $this->assertDatabaseHas('note_application_ledger', ['target_kind' => 'WALLET', 'status' => 'FAILED', 'failure_reason' => 'WALLET_INSUFFICIENT_BALANCE']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteApplicationFailed']);

        // Customer tops up; admin retries — now it applies.
        $wallets->credit($wallet->refresh(), 900, 'TOPUP');
        $this->postJson("/api/adjustments/{$adjustmentId}/retry-application")->assertOk()
            ->assertJsonPath('status', 'APPLIED');
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_debit', 'balance' => 200.00]);
    }

    public function test_limits_block_until_overridden_and_rejection_is_audited(): void
    {
        $invoice = $this->postpaidInvoice(80000, 'acc_big', 'cust_big'); // above max_per_request 50000

        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'BILLING_ERROR_CORRECTION',
        ], ['Idempotency-Key' => 'adj-limit-1'])->assertCreated();
        $adjustmentId = $res->json('adjustment_id');
        $this->assertSame('ADJUSTMENT_LIMIT_EXCEEDED', $res->json('failure_reason'));

        // Approval is blocked until the limit is explicitly overridden.
        $this->postJson("/api/adjustments/{$adjustmentId}/approve")->assertStatus(422)
            ->assertJsonPath('errorCode', 'LIMIT_OVERRIDE_REQUIRED');
        $this->postJson("/api/adjustments/{$adjustmentId}/override-limit")->assertOk()
            ->assertJsonPath('status', 'PENDING_APPROVAL');

        // This time reject — decision lands in the audit trail and the request closes.
        $this->postJson("/api/adjustments/{$adjustmentId}/reject", ['comment' => 'not justified'])->assertOk()
            ->assertJsonPath('status', 'REJECTED');
        $this->assertDatabaseHas('adjustment_approval_step', ['adjustment_id' => $adjustmentId, 'decision' => 'REJECTED']);

        // No further decisions on a closed request.
        $this->postJson("/api/adjustments/{$adjustmentId}/approve")->assertStatus(409);
    }

    public function test_validation_unknown_reason_and_tax_invoice_protection(): void
    {
        // Unknown reason code is rejected against the operator catalog.
        $invoice = $this->postpaidInvoice(100);
        $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'MADE_UP',
        ], ['Idempotency-Key' => 'adj-bad-reason'])->assertStatus(422)->assertJsonPath('errorCode', 'UNKNOWN_REASON_CODE');

        // A signed tax invoice cannot be adjusted — adjust the commercial invoice.
        $tax = $this->postpaidInvoice(100);
        $tax->update(['type' => 'TAX']);
        $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $tax->invoice_id,
            'reason_code' => 'DISPUTE_RESOLVED',
        ], ['Idempotency-Key' => 'adj-tax'])->assertStatus(422)->assertJsonPath('errorCode', 'CANNOT_ADJUST_TAX_INVOICE');
    }

    public function test_small_credit_auto_approves_under_threshold_and_note_is_readable(): void
    {
        $invoice = $this->postpaidInvoice(400, 'acc_auto', 'cust_auto');

        // 400 < auto_approve_under 500 → zero-step approval, applied in-line.
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'GOODWILL_CREDIT',
        ], ['Idempotency-Key' => 'adj-auto-1'])->assertCreated();

        $this->assertSame('APPLIED', $res->json('status'));
        $this->assertDatabaseHas('adjustment_approval_step', ['adjustment_id' => $res->json('adjustment_id'), 'decided_by' => 'SYSTEM:AUTO_APPROVE']);

        // GET /api/credit-notes/{id} returns the note + its application ledger.
        $noteId = $res->json('note_invoice_id');
        $this->getJson("/api/credit-notes/{$noteId}")->assertOk()
            ->assertJsonPath('note.type', 'CREDIT_NOTE')
            ->assertJsonPath('applications.0.target_kind', 'INVOICE');
    }
}
