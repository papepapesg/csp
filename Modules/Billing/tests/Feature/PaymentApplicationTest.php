<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\PaymentLedger;
use Modules\Billing\Services\InvoiceService;
use Modules\Billing\Services\PaymentService;
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
