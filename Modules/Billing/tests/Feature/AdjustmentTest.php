<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Database\Seeders\AdjustmentConfigSeeder;
use Modules\Billing\Adjustments\Models\AdjustmentRequest;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Invoicing\Services\InvoiceService;
use Modules\Billing\Wallet\Services\WalletService;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Tests\TestCase;

/**
 * BIL-02-ADJ-01 + BIL-01-CN-01: the governed adjustment pipeline — proposal,
 * approval, note issuance and application across POSTPAID invoices (with
 * surplus to the account credit balance) and PREPAID wallets (insufficient
 * balance fails, /retry-application after top-up succeeds).
 *
 * Reading the events in these tests — issuance and application are two
 * distinct stages, hence two distinct events:
 *  - CreditNoteIssued / DebitNoteIssued  → GEN-01 created the note DOCUMENT
 *    (an invoice row, type CREDIT_NOTE/DEBIT_NOTE, with its own legal number).
 *    No money has moved yet.
 *  - CreditNoteApplied / DebitNoteApplied → CN-01 MOVED THE MONEY (one event
 *    per ledger row: parent outstanding reduced, wallet credited/debited, or
 *    surplus to the account credit balance).
 * They can diverge: a note can be issued while its application FAILS (e.g.
 * insufficient wallet) and is retried later — which is exactly why the two
 * stages are observable separately.
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

    /**
     * EXPECTATION — the happy path, end to end.
     * Given a postpaid invoice of 2,500 fully outstanding,
     * when an agent proposes a FULL credit adjustment (reason DISPUTE_RESOLVED)
     * and one approver approves it (routing: SINGLE),
     * then:
     *  - the proposal waits in PENDING_APPROVAL with the amount taken from the parent total;
     *  - on approval a CREDIT_NOTE invoice is ISSUED against the parent, carrying its own
     *    CN-… legal number (event: CreditNoteIssued — the document exists, no money moved);
     *  - the note is then APPLIED: the parent's outstanding drops to zero and the parent
     *    becomes PAID, recorded as one ledger row (event: CreditNoteApplied — money moved);
     *  - the reason's GL account flows reason → adjustment request → application ledger,
     *    so finance can reconcile the credit end to end.
     */
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

        // The note is a CREDIT_NOTE invoice with its own CN legal number: face value 2500
        // (total_amount, what the document asserts), amount_due 0 (a note is never a receivable).
        $this->assertDatabaseHas('invoice', ['invoice_id' => $noteId, 'type' => 'CREDIT_NOTE', 'original_invoice_id' => $invoice->invoice_id, 'status' => 'ISSUED', 'total_amount' => 2500.00, 'amount_due' => 0.00]);
        $this->assertStringStartsWith('CN-WIK-', Invoice::query()->find($noteId)->legal_invoice_number);

        // Parent outstanding cleared; one ledger row, fully applied.
        $this->assertDatabaseHas('invoice', ['invoice_id' => $invoice->invoice_id, 'amount_due' => 0.00, 'status' => 'PAID']);
        $this->assertDatabaseHas('note_application_ledger', ['note_id' => $noteId, 'target_kind' => 'INVOICE', 'target_id' => $invoice->invoice_id, 'applied_amount' => 2500.00, 'status' => 'APPLIED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteIssued']);   // document created
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteApplied']);  // money moved

        // Finance: the reason's GL account (DISPUTE_RESOLVED credit) flows reason → request → ledger.
        $this->assertDatabaseHas('adjustment_request', ['adjustment_id' => $adjustmentId, 'gl_code' => '4000-REVENUE-ADJ-CR']);
        $this->assertDatabaseHas('note_application_ledger', ['note_id' => $noteId, 'gl_code' => '4000-REVENUE-ADJ-CR']);
    }

    /**
     * EXPECTATION — a credit larger than the debt never over-credits the invoice.
     * Given a postpaid invoice with 1,000 outstanding,
     * when a 1,500 AMOUNT-scope credit adjustment is approved,
     * then the application SPLITS into two ledger rows in one transaction:
     *  - 1,000 clears the invoice (capped at its outstanding),
     *  - the 500 surplus lands on the account credit balance (available for
     *    future invoices), never as a negative amount_due.
     */
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

    /**
     * EXPECTATION — a debit note makes a settled invoice payable again.
     * Given a postpaid invoice already PAID in full (800),
     * when a 600 DEBIT adjustment (late fee) is approved — debits always need
     * a human, whatever the amount —
     * then a DEBIT_NOTE invoice of its own is issued (DN-… legal number,
     * status ISSUED, amount_due 0 — the note itself is never a receivable),
     * the PARENT's outstanding grows to 600 and its status drops from
     * PAID back to PARTIALLY_PAID, and both stages are observable:
     * DebitNoteIssued (document) then DebitNoteApplied (outstanding grew).
     */
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

        $approved = $this->postJson('/api/adjustments/'.$res->json('adjustment_id').'/approve')->assertOk();

        // The debit note is a document of its own: DN legal number, face value 600 —
        // and never a receivable itself (amount_due stays 0; the PARENT carries the debt).
        $noteId = $approved->json('note_invoice_id');
        $this->assertDatabaseHas('invoice', ['invoice_id' => $noteId, 'type' => 'DEBIT_NOTE', 'original_invoice_id' => $invoice->invoice_id, 'status' => 'ISSUED', 'total_amount' => 600.00, 'amount_due' => 0.00]);
        $this->assertStringStartsWith('DN-WIK-', Invoice::query()->find($noteId)->legal_invoice_number);

        // Outstanding grows on the PARENT; the PAID invoice becomes payable again (R-CN-01-AP-2).
        $this->assertDatabaseHas('invoice', ['invoice_id' => $invoice->invoice_id, 'amount_due' => 600.00, 'status' => 'PARTIALLY_PAID']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteIssued']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteApplied']);
    }

    /**
     * EXPECTATION — prepaid adjustments target the wallet, not an invoice.
     * Given a prepaid subscription (no invoice at all),
     * when a 600 goodwill CREDIT adjustment is approved,
     * then the SAME two-stage pipeline runs as for postpaid: a CREDIT_NOTE
     * document is issued (face value 600, no parent to reference —
     * original_invoice_id null; CreditNoteIssued), and its application
     * credits the customer's default money wallet by 600, recorded in the
     * ledger with the wallet as target (CreditNoteApplied).
     */
    public function test_prepaid_credit_note_credits_the_wallet(): void
    {
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'AMOUNT', 'amount' => 600,
            'billing_mode' => 'PREPAID', 'subscription_id' => 'sub_prepaid_adj',
            'customer_id' => 'cust_pp', 'account_id' => 'acc_pp',
            'service_category_code' => 'GOODWILL',
            'reason_code' => 'GOODWILL_CREDIT',
        ], ['Idempotency-Key' => 'adj-prepaid-credit'])->assertCreated();

        $approved = $this->postJson('/api/adjustments/'.$res->json('adjustment_id').'/approve')->assertOk()
            ->assertJsonPath('status', 'APPLIED');

        // Same document model as postpaid — just no parent invoice to reference.
        $this->assertDatabaseHas('invoice', ['invoice_id' => $approved->json('note_invoice_id'), 'type' => 'CREDIT_NOTE', 'original_invoice_id' => null, 'total_amount' => 600.00, 'amount_due' => 0.00]);

        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_adj', 'wallet_code' => 'MONEY_KES', 'balance' => 600.00]);
        $this->assertDatabaseHas('note_application_ledger', ['target_kind' => 'WALLET', 'target_id' => 'MONEY_KES', 'applied_amount' => 600.00, 'status' => 'APPLIED']);

        // Both pipeline stages are observable on the prepaid path too.
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteIssued']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'CreditNoteApplied']);
    }

    /**
     * EXPECTATION — issuance and application are separate stages that can diverge.
     * Given a prepaid wallet holding only 300,
     * when a 1,000 DEBIT adjustment is approved,
     * then the note document IS issued (DebitNoteIssued, face value 1,000) but
     * its application FAILS (the wallet is never driven negative): status
     * APPLICATION_FAILED, a FAILED ledger row, the dedicated
     * DebitNoteApplicationFailed alert — and NO DebitNoteApplied event yet,
     * while the balance stays 300. Issued-without-Applied is the divergence
     * this whole two-event design exists for.
     * When the customer tops up 900 and an admin retries the application,
     * then the SAME persisted note applies (no re-issue): DebitNoteApplied
     * finally fires and the wallet ends at 200 (300 + 900 − 1,000).
     */
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
        $approved = $this->postJson("/api/adjustments/{$adjustmentId}/approve")->assertOk()
            ->assertJsonPath('status', 'APPLICATION_FAILED')
            ->assertJsonPath('failure_reason', 'WALLET_INSUFFICIENT_BALANCE');
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_debit', 'balance' => 300.00]);
        $this->assertDatabaseHas('note_application_ledger', ['target_kind' => 'WALLET', 'status' => 'FAILED', 'failure_reason' => 'WALLET_INSUFFICIENT_BALANCE']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteApplicationFailed']);

        // The stages diverge HERE: the note document IS issued (that's what retry re-applies
        // later), but nothing is applied yet — Issued without Applied.
        $this->assertDatabaseHas('invoice', ['invoice_id' => $approved->json('note_invoice_id'), 'type' => 'DEBIT_NOTE', 'total_amount' => 1000.00, 'amount_due' => 0.00, 'status' => 'ISSUED']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteIssued']);
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'DebitNoteApplied']);

        // Customer tops up; admin retries — now it applies (same persisted note, no re-issue).
        $wallets->credit($wallet->refresh(), 900, 'TOPUP');
        $this->postJson("/api/adjustments/{$adjustmentId}/retry-application")->assertOk()
            ->assertJsonPath('status', 'APPLIED');
        $this->assertDatabaseHas('wallet', ['subscription_id' => 'sub_prepaid_debit', 'balance' => 200.00]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'DebitNoteApplied']);
    }

    /**
     * EXPECTATION — operator guard-rails park oversized proposals; every outcome is audited.
     * Given the operator's max_per_request limit is 50,000,
     * when an 80,000 credit is proposed,
     * then the proposal parks as limit-breached (no approval gate yet) and any
     * approve attempt is refused with LIMIT_OVERRIDE_REQUIRED.
     * When an approver explicitly overrides the limit,
     * then the proposal enters PENDING_APPROVAL like any other.
     * When it is then rejected,
     * then the rejection lands in the audit timeline, the request closes,
     * and no further decisions are accepted (409).
     */
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

    /**
     * EXPECTATION — proposals are validated against operator config and fiscal law.
     * When a proposal cites a reason code that is not in the operator catalog,
     * then it is refused (UNKNOWN_REASON_CODE) — reasons are config, not free text.
     * When a proposal targets a signed TAX invoice,
     * then it is refused (CANNOT_ADJUST_TAX_INVOICE) — the fiscal document is
     * immutable; the commercial invoice is what gets adjusted.
     */
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

    /**
     * EXPECTATION — big credits take the DUAL process, enforced by the approval engine.
     * Given a 25,000 credit proposal (≥ 20,000 → routing rule R-ADJ-APPR-4
     * selects the DUAL process; the deciding rule is pinned on the proposal),
     * then one approval is NOT enough — the proposal stays PENDING_APPROVAL;
     * and the SAME person approving a second time is refused by the engine
     * (DUPLICATE_STAGE_APPROVER) — dual control means two DISTINCT humans;
     * and only a second, different approver clears the gate, after which the
     * note is issued + applied and both approvals sit in the audit timeline.
     */
    public function test_approval_routing_is_a_rules_engine_decision_dual_control_for_large_credits(): void
    {
        // 25000 ≥ 20000 → R-ADJ-APPR-4 (DUAL_CONTROL): two audited approvals.
        $invoice = $this->postpaidInvoice(25000, 'acc_dual', 'cust_dual');
        $res = $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'DISPUTE_RESOLVED',
        ], ['Idempotency-Key' => 'adj-dual-1'])->assertCreated();
        $adjustmentId = $res->json('adjustment_id');

        // The routing decision (and deciding rule) is pinned on the proposal.
        $this->assertSame(2, $res->json('required_approvals'));
        $this->assertSame('R-ADJ-APPR-4', $res->json('approval_rule_id'));

        // First approval is not enough (EM-CFG-04 gate, quorum 2).
        $this->postJson("/api/adjustments/{$adjustmentId}/approve", ['comment' => 'lead ok'])->assertOk()
            ->assertJsonPath('status', 'PENDING_APPROVAL');

        // Dual control means TWO DISTINCT approvers — the same person approving again is refused.
        $this->postJson("/api/adjustments/{$adjustmentId}/approve", ['comment' => 'again'])->assertStatus(409)
            ->assertJsonPath('errorCode', 'DUPLICATE_STAGE_APPROVER');

        // A second, distinct approver clears the gate → applied.
        $second = User::factory()->create(['operator_code' => 'WIK']);
        $second->assignRole('BILLING_LEAD');
        Sanctum::actingAs($second);
        $this->postJson("/api/adjustments/{$adjustmentId}/approve", ['comment' => 'manager ok'])->assertOk()
            ->assertJsonPath('status', 'APPLIED');
        $this->assertSame(2, \Modules\Billing\Adjustments\Models\AdjustmentApprovalStep::query()
            ->where('adjustment_id', $adjustmentId)->where('decision', 'APPROVED')->count());
    }

    /**
     * EXPECTATION — approval routing is operator-overridable configuration, not code.
     * Given WIK deploys its own version of the adjustment-approval rule set
     * ("every credit auto-approves"),
     * when a 5,000 credit is proposed (the GLOBAL table would demand one approval),
     * then WIK's table wins: the proposal auto-approves and applies immediately,
     * and the WIK rule's id is pinned on the proposal as the deciding rule.
     */
    public function test_operator_scoped_rule_table_overrides_the_global_routing(): void
    {
        // WIK deploys its own version of the rule set: every credit auto-approves.
        \Modules\Rules\Models\DecisionTable::query()->create([
            'table_id' => \App\Foundation\Support\Id::make('dt'),
            'rule_set' => 'rules.billing.adjustment-approval',
            'operator_code' => 'WIK', 'version' => 1,
            'name' => 'WIK adjustment routing', 'hit_policy' => 'FIRST',
            'inputs' => ['direction', 'amount'],
            'rules' => [['ruleId' => 'R-WIK-ADJ-1', 'when' => [['var' => 'direction', 'op' => 'eq', 'value' => 'CREDIT']],
                'then' => ['stepsRequired' => 0, 'decisionCode' => 'WIK_TRUSTS_CREDITS']]],
            'default_output' => ['stepsRequired' => 1],
            'status' => \Modules\Rules\Models\DecisionTable::DEPLOYED,
        ]);

        // 5000 would be SINGLE_APPROVAL globally — WIK's table auto-approves it.
        $invoice = $this->postpaidInvoice(5000, 'acc_wik_rule', 'cust_wik_rule');
        $this->postJson('/api/adjustments', [
            'direction' => 'CREDIT', 'scope' => 'FULL',
            'parent_invoice_id' => $invoice->invoice_id,
            'reason_code' => 'GOODWILL_CREDIT',
        ], ['Idempotency-Key' => 'adj-wik-rule'])->assertCreated()
            ->assertJsonPath('status', 'APPLIED')
            ->assertJsonPath('approval_rule_id', 'R-WIK-ADJ-1');
    }

    /**
     * EXPECTATION — small credits flow without friction, but never off the books.
     * Given a 400 credit proposal (under the 500 auto-approve threshold → the
     * AUTO process, which the approval engine itself records as auto-approved),
     * then the proposal applies immediately in the same request, with a
     * SYSTEM:AUTO_APPROVE step in the audit timeline — auto ≠ unaudited.
     * And the issued note is readable via GET /api/credit-notes/{id},
     * returning the document together with its application ledger.
     */
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
