<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-GEN-01 operational features:
 *   - generation_failure_queue (rule group Q): recoverable generation failures
 *     persisted with full context so a scanner retries them with backoff instead
 *     of losing the invoice or blocking the caller.
 *   - bulk_reversal_batch (rule group R): operator-initiated cancel/re-issue of a
 *     whole batch billed with a systemic error, dual-approved and audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_failure_queue', function (Blueprint $table) {
            $table->string('failure_id')->primary();             // gfq_...
            $table->string('operator_code')->index();
            $table->string('trigger_code');                      // CYCLE_POSTPAID | ONE_OFF_INVOICE | …
            $table->string('subscription_id')->nullable()->index();
            $table->string('triggering_event_ref')->nullable();
            $table->json('context');                             // header + charges needed to retry
            $table->string('reason_code');                       // CUSTOMER_SNAPSHOT_FETCH_FAILED | BIL01_UNAVAILABLE | TAX_SERVICE_FAILED | …
            $table->text('reason_detail')->nullable();
            $table->string('status')->default('PENDING_RETRY')->index(); // PENDING_RETRY | RETRIED_SUCCESS | GAVE_UP_AUTO | RESOLVED_NO_ACTION
            $table->unsignedInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('bulk_reversal_batch', function (Blueprint $table) {
            $table->string('batch_id')->primary();               // brb_...
            $table->string('operator_code')->index();
            $table->string('invoice_type')->nullable();          // scope: STANDARD | CYCLE_POSTPAID | …
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->json('filters')->nullable();                 // optional package/wallet/category filter
            $table->boolean('re_issue')->default(false);
            $table->string('status')->default('PENDING_APPROVAL')->index(); // PENDING_APPROVAL | IN_PROGRESS | COMPLETED | PARTIALLY_FAILED | REJECTED
            $table->unsignedInteger('invoices_in_scope')->default(0);
            $table->unsignedInteger('invoices_cancelled')->default(0);
            $table->unsignedInteger('invoices_re_issued')->default(0);
            $table->unsignedInteger('invoices_failed')->default(0);
            $table->string('proposed_by')->nullable();           // BILLING_ADMIN
            $table->string('approved_by')->nullable();           // FINANCE_HEAD (dual control, R-GEN-01-R-1)
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Cancellation provenance on the invoice (R-GEN-01-R-3).
        Schema::table('invoice', function (Blueprint $table) {
            $table->string('cancel_reason_code')->nullable()->after('status');
            $table->string('cancel_batch_id')->nullable()->index()->after('cancel_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn(['cancel_reason_code', 'cancel_batch_id']);
        });
        Schema::dropIfExists('bulk_reversal_batch');
        Schema::dropIfExists('generation_failure_queue');
    }
};
