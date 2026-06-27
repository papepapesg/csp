<?php

namespace Modules\Billing\Tests\Feature;

use App\Foundation\Support\Context;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Billing\Invoicing\Models\Invoice;
use Modules\Billing\Invoicing\Services\GenerationFailureService;
use Modules\Billing\Invoicing\Services\InvoiceService;
use Tests\TestCase;

/**
 * BIL-02-GEN-01 operational features: rule group R (bulk reversal, dual-approved,
 * protected states rejected) and rule group Q (generation failure queue).
 */
class BulkReversalAndFailureQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Context::setOperatorCode('WIK');
    }

    private function invoice(string $type = 'STANDARD', float $amount = 1000): Invoice
    {
        return app(InvoiceService::class)->generate(
            ['account_id' => 'acc_1', 'customer_id' => 'cust_1', 'type' => $type],
            [['description' => 'x', 'quantity' => 1, 'unit_price' => $amount]],
        );
    }

    private function actAs(string $role): User
    {
        $u = User::factory()->create(['operator_code' => 'WIK']);
        $u->assignRole($role);
        Sanctum::actingAs($u);

        return $u;
    }

    public function test_bulk_reversal_preview_separates_eligible_from_protected(): void
    {
        $this->actAs('BILLING_LEAD');
        $this->invoice('STANDARD', 1000);
        $this->invoice('TAX', 500);            // protected: signed tax invoice
        $parent = $this->invoice('STANDARD', 800);
        // a credit note linked to the parent protects the parent
        Invoice::query()->create([
            'operator_code' => 'WIK', 'account_id' => 'acc_1', 'type' => 'CREDIT_NOTE',
            'original_invoice_id' => $parent->invoice_id, 'status' => 'ISSUED', 'currency' => 'KES',
            'issue_date' => now(), 'total_amount' => 100, 'amount_due' => 0,
            'legal_invoice_number' => 'CN-WIK-2026-000001',
        ]);

        $preview = $this->postJson('/api/billing/bulk-reversals/preview', ['invoice_type' => null])->assertOk();
        // 4 invoices in scope; only the first STANDARD is eligible (tax + note-linked parent protected; CN itself is type CREDIT_NOTE not STANDARD but still scanned)
        $this->assertGreaterThanOrEqual(2, $preview->json('protected') ? count($preview->json('protected')) : 0);
        $reasons = array_column($preview->json('protected'), 'reason');
        $this->assertContains('SIGNED_TAX_INVOICE', $reasons);
        $this->assertContains('HAS_LINKED_NOTES', $reasons);
    }

    public function test_dual_controlled_bulk_reversal_cancels_eligible_and_skips_protected(): void
    {
        $proposer = $this->actAs('BILLING_LEAD');
        $std = $this->invoice('STANDARD', 1000);
        $tax = $this->invoice('TAX', 500);

        $batch = $this->postJson('/api/billing/bulk-reversals', ['invoice_type' => 'STANDARD', 'notes' => 'wrong rate'])
            ->assertCreated()->json('batch_id');

        // Same user cannot approve their own proposal (dual control, R-GEN-01-R-1).
        $this->postJson("/api/billing/bulk-reversals/{$batch}/approve")->assertStatus(422)
            ->assertJsonPath('errorCode', 'DUAL_CONTROL_REQUIRED');

        // A different approver executes it.
        $this->actAs('BILLING_LEAD');
        $this->postJson("/api/billing/bulk-reversals/{$batch}/approve")->assertOk()->assertJsonPath('cancelled', 1);

        $this->assertDatabaseHas('invoice', ['invoice_id' => $std->invoice_id, 'status' => 'VOID', 'cancel_reason_code' => 'BULK_REVERSAL', 'cancel_batch_id' => $batch]);
        $this->assertDatabaseHas('invoice', ['invoice_id' => $tax->invoice_id, 'status' => 'OPEN']); // protected untouched
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'InvoiceCancelled']);
        $this->assertDatabaseHas('bulk_reversal_batch', ['batch_id' => $batch, 'status' => 'COMPLETED', 'invoices_cancelled' => 1]);
    }

    public function test_generation_failure_queue_enqueues_and_resolves(): void
    {
        $failures = app(GenerationFailureService::class);
        $failures->enqueue('WIK', 'CYCLE_POSTPAID', 'sub_x', ['subscriptionId' => 'sub_x'], 'CUSTOMER_SNAPSHOT_FETCH_FAILED', 'not found');
        $this->assertDatabaseHas('generation_failure_queue', ['subscription_id' => 'sub_x', 'reason_code' => 'CUSTOMER_SNAPSHOT_FETCH_FAILED', 'status' => 'PENDING_RETRY']);

        // Repeated failure bumps the same row, not a new one.
        $failures->enqueue('WIK', 'CYCLE_POSTPAID', 'sub_x', ['subscriptionId' => 'sub_x'], 'BIL01_UNAVAILABLE', 'timeout');
        $this->assertSame(1, DB::table('generation_failure_queue')->where('subscription_id', 'sub_x')->count());

        // A successful generation resolves it.
        $failures->resolveFor('WIK', 'sub_x', 'CYCLE_POSTPAID');
        $this->assertDatabaseHas('generation_failure_queue', ['subscription_id' => 'sub_x', 'status' => 'RETRIED_SUCCESS']);
    }

    public function test_retry_scanner_recovers_a_due_entry(): void
    {
        $failures = app(GenerationFailureService::class);
        $failures->enqueue('WIK', 'CYCLE_POSTPAID', 'sub_ok', ['subscriptionId' => 'sub_ok'], 'BIL01_UNAVAILABLE', 'timeout');
        // enqueue parks next_retry_at 15 min out — make it due now.
        DB::table('generation_failure_queue')->where('subscription_id', 'sub_ok')->update(['next_retry_at' => now()->subMinute()]);

        $r = $failures->retryDue('WIK', fn () => null); // the generator now succeeds (no throw)

        $this->assertSame(1, $r['recovered']);
        $this->assertDatabaseHas('generation_failure_queue', ['subscription_id' => 'sub_ok', 'status' => 'RETRIED_SUCCESS']);
    }

    public function test_retry_scanner_skips_entries_not_yet_due(): void
    {
        $failures = app(GenerationFailureService::class);
        $failures->enqueue('WIK', 'CYCLE_POSTPAID', 'sub_future', ['subscriptionId' => 'sub_future'], 'BIL01_UNAVAILABLE', 'timeout');

        // next_retry_at is in the future — the scanner must not touch it.
        $r = $failures->retryDue('WIK', fn () => throw new \RuntimeException('should not run'));

        $this->assertSame(0, $r['retried']);
        $this->assertDatabaseHas('generation_failure_queue', ['subscription_id' => 'sub_future', 'status' => 'PENDING_RETRY', 'retry_count' => 0]);
    }

    public function test_retry_scanner_gives_up_after_the_retry_budget(): void
    {
        $failures = app(GenerationFailureService::class);
        $failures->enqueue('WIK', 'CYCLE_POSTPAID', 'sub_bad', ['subscriptionId' => 'sub_bad'], 'BIL01_UNAVAILABLE', 'timeout');

        // The generator stays broken across the whole budget (8 attempts); re-arm each pass.
        $broken = fn () => throw new \RuntimeException('still down');
        for ($i = 0; $i < 8; $i++) {
            DB::table('generation_failure_queue')->where('subscription_id', 'sub_bad')->update(['next_retry_at' => now()->subMinute()]);
            $failures->retryDue('WIK', $broken);
        }

        // Budget spent → GAVE_UP_AUTO for human review, no further auto-retry.
        $this->assertDatabaseHas('generation_failure_queue', ['subscription_id' => 'sub_bad', 'status' => 'GAVE_UP_AUTO', 'retry_count' => 8]);
        $this->assertSame(0, $failures->retryDue('WIK', $broken)['retried']);
    }
}
