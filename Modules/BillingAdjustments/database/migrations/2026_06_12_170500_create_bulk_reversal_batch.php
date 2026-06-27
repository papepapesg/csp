<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-GEN-01 rule group R — bulk reversal batch. Owned by the BillingAdjustments module
 * (carved out of the shared generation-failure migration). No FK; the invoice cancel-provenance
 * columns (cancel_reason_code / cancel_batch_id) stay on the invoice table in the base module.
 */
return new class extends Migration
{
    public function up(): void
    {
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
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_reversal_batch');
    }
};
