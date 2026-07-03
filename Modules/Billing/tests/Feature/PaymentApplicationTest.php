<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Payments\Models\PaymentLedger;
use Modules\Billing\Invoicing\Services\InvoiceService;
use Modules\Billing\Payments\Services\PaymentService;
use Tests\TestCase;

/** BIL-01-PAY-01 payment application: idempotency, allocation policy, overpayment, reversal. */
class PaymentApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    private function invoice(float $amount, string $account = 'acc_1', ?\Carbon\Carbon $due = null): Invoice
    {
        $inv = app(InvoiceService::class)->generate(['account_id' => $account], [['description' => 'x', 'quantity' => 1, 'unit_price' => $amount]]);
        if ($due) {
            $inv->update(['due_date' => $due]);
        }

        return $inv;
    }

    public function test_receipt_is_idempotent_on_account_and_reference(): void
    {
        $this->invoice(1000);
        $svc = app(PaymentService::class);
        $a = $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 1000, 'method' => 'MPESA', 'payment_reference' => 'TX-1']);
        $b = $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 1000, 'method' => 'MPESA', 'payment_reference' => 'TX-1']);

        $this->assertSame($a->payment_id, $b->payment_id);     // same result, not re-applied
        $this->assertSame(1, PaymentLedger::count());
    }

    public function test_allocation_policy_largest_first(): void
    {
        DB::table('payment_config')->insert(['operator_code' => 'WIK', 'allocation_policy' => 'LARGEST_FIRST', 'overpayment_policy' => 'APPLY_TO_NEXT_OPEN', 'created_at' => now(), 'updated_at' => now()]);
        $small = $this->invoice(200);
        $big = $this->invoice(900);

        app(PaymentService::class)->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 900, 'method' => 'OFFLINE', 'payment_reference' => 'p1']);
        // Largest first: the 900 invoice is fully paid, the 200 untouched.
        $this->assertSame('PAID', $big->fresh()->status);
        $this->assertSame('OPEN', $small->fresh()->status);
    }

    public function test_overpayment_surplus_goes_to_credit_balance_and_directed_allocation(): void
    {
        $inv = $this->invoice(300);
        $svc = app(PaymentService::class);
        $p = $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 500, 'method' => 'MPESA', 'payment_reference' => 'over-1', 'target_invoice_id' => $inv->invoice_id]);

        $this->assertSame('PAID', $inv->fresh()->status);
        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acc_1', 'balance' => 200.00]);
        $alloc = DB::table('payment_invoice_allocation')->where('payment_id', $p->payment_id)->first();
        $this->assertEquals(300.00, $alloc->allocated_amount);   // AL-5 audit fields
        $this->assertEquals(300.00, $alloc->outstanding_before);
        $this->assertEquals(0.00, $alloc->outstanding_after);
    }

    public function test_payment_with_no_open_invoices_is_unallocated_not_rejected(): void
    {
        $p = app(PaymentService::class)->receiveAndApply(['account_id' => 'acc_empty', 'paid_amount' => 400, 'method' => 'MPESA', 'payment_reference' => 'u-1']);
        $this->assertSame('UNALLOCATED', $p->status);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PaymentReceivedUnallocated']);
    }

    public function test_ov2_credit_balance_auto_draws_against_a_new_invoice(): void
    {
        $svc = app(PaymentService::class);
        // Overpay an invoice → 400 surplus on the account credit balance.
        $first = $this->invoice(100);
        $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 500, 'method' => 'MPESA', 'payment_reference' => 'ov2-pay', 'target_invoice_id' => $first->invoice_id]);
        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acc_1', 'balance' => 400.00]);

        // A new invoice for the account auto-draws the credit (OV-2).
        $next = $this->invoice(300);
        $svc->applyCreditBalanceToInvoice($next->invoice_id);

        $this->assertSame('PAID', $next->fresh()->status);
        $this->assertDatabaseHas('account_credit_balance', ['account_id' => 'acc_1', 'balance' => 100.00]); // 400 - 300
        $this->assertDatabaseHas('payment_ledger', ['account_id' => 'acc_1', 'method' => 'CREDIT_BALANCE_APPLICATION']);
    }

    public function test_ov2_listener_fires_on_invoice_generated(): void
    {
        $svc = app(PaymentService::class);
        $first = $this->invoice(100);
        $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 300, 'method' => 'MPESA', 'payment_reference' => 'ov2l', 'target_invoice_id' => $first->invoice_id]);

        $next = $this->invoice(150);
        $event = \App\Foundation\Events\Outbox\OutboxEvent::query()
            ->where('event_type', 'InvoiceGenerated')->whereJsonContains('payload->invoiceId', $next->invoice_id)->firstOrFail();
        app(\Modules\Billing\Invoicing\Listeners\ApplyCreditBalanceOnInvoice::class)->handle(new \App\Foundation\Events\OutboxEventPublished($event));

        $this->assertSame('PAID', $next->fresh()->status);
    }

    public function test_ov3_wallet_topup_overflow_credits_the_wallet(): void
    {
        $this->seed(\Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder::class);
        DB::table('payment_config')->insert(['operator_code' => 'WIK', 'allocation_policy' => 'FIFO_DUE_DATE', 'overpayment_policy' => 'WALLET_TOPUP', 'overpayment_overflow_wallet_ref' => 'MONEY', 'created_at' => now(), 'updated_at' => now()]);
        \Modules\Subscription\Models\Subscription::query()->create([
            'subscription_id' => \App\Foundation\Support\Id::make('sub'), 'customer_id' => 'c1', 'account_id' => 'acc_w',
            'operator_code' => 'WIK', 'homepass_id' => 'h1', 'package_ref' => 'p', 'status_code' => 'ACTIVE', 'billing_mode' => 'POSTPAID', 'currency' => 'KES',
        ]);
        $inv = $this->invoice(200, 'acc_w');

        // Pay 500 against a 200 invoice → 300 surplus routes to the overflow wallet.
        app(PaymentService::class)->receiveAndApply(['account_id' => 'acc_w', 'paid_amount' => 500, 'method' => 'MPESA', 'payment_reference' => 'ov3', 'target_invoice_id' => $inv->invoice_id]);

        $this->assertDatabaseHas('wallet', ['account_id' => 'acc_w', 'wallet_code' => 'MONEY', 'balance' => 300.00]);
        $this->assertDatabaseMissing('account_credit_balance', ['account_id' => 'acc_w']);
    }

    public function test_reversal_restores_invoice_outstanding_and_writes_lineage(): void
    {
        $inv = $this->invoice(1000);
        $svc = app(PaymentService::class);
        $p = $svc->receiveAndApply(['account_id' => 'acc_1', 'paid_amount' => 1000, 'method' => 'MPESA', 'payment_reference' => 'rev-1']);
        $this->assertSame('PAID', $inv->fresh()->status);

        $reversal = $svc->reverse($p, 'CHARGEBACK', 'admin_1');

        // Original reversed, invoice re-opened, lineage row written, event emitted.
        $this->assertSame('REVERSED', $p->fresh()->status);
        $this->assertSame('OPEN', $inv->fresh()->status);
        $this->assertEquals(1000.00, $inv->fresh()->amount_due);
        $this->assertSame($p->payment_id, $reversal->reversal_of_payment_id);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'PaymentReversed']);
    }
}
