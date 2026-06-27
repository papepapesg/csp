<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-04 alignment: billing-mode + triggering-event provenance on the dunning
 * state, the at-most-daily evaluation clock, the pre-termination review window,
 * and an operator dunning config (pre_termination_review_required).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dunning_state', function (Blueprint $table) {
            $table->string('billing_mode')->default('POSTPAID')->after('subscription_id'); // POSTPAID | PREPAID | PREPAYMENT
            $table->string('triggering_event_type')->nullable()->after('billing_mode');     // InvoiceOverdue | CyclePaymentMissed
            $table->timestamp('next_evaluation_at')->nullable()->index()->after('last_scanned_at'); // E-1/E-2 cadence
            $table->timestamp('review_due_at')->nullable()->after('next_evaluation_at');     // T-4 review window
            $table->string('last_workflow_failure_code')->nullable()->after('review_due_at');
        });

        // pre_termination_review_required per operator (C-4; default TRUE).
        Schema::create('dunning_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->boolean('pre_termination_review_required')->default(true);
            $table->unsignedInteger('review_window_hours')->default(72);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_config');
        Schema::table('dunning_state', function (Blueprint $table) {
            $table->dropColumn(['billing_mode', 'triggering_event_type', 'next_evaluation_at', 'review_due_at', 'last_workflow_failure_code']);
        });
    }
};
