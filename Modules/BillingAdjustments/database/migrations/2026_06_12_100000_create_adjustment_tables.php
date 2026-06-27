<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-ADJ-01 invoice adjustments + BIL-01-CN-01 note application.
 *
 * An adjustment is a governed PROPOSAL (proposer → approval → application) that,
 * once approved, issues a CREDIT_NOTE or DEBIT_NOTE invoice (GEN-01) and applies
 * it: POSTPAID against the parent invoice's outstanding (surplus → account credit
 * balance), PREPAID against the customer's wallet (BIL-05). Every application is
 * recorded in the append-only note_application_ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Operator-governed reason-code catalog: every adjustment must carry one.
        Schema::create('adjustment_reason_code', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('code');                              // e.g. DISPUTE_RESOLVED, BILLING_ERROR, SLA_COMPENSATION, GOODWILL
            $table->string('description');
            $table->string('direction')->default('ANY');         // CREDIT | DEBIT | ANY — which note directions this reason may justify
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        // adjustment guard-rails (limits) now live on the base ADJUSTMENT approval_definition's
        // config (EM-CFG-04 "config on the process row"), not a separate per-operator table.

        // The adjustment proposal and its lifecycle.
        Schema::create('adjustment_request', function (Blueprint $table) {
            $table->string('adjustment_id')->primary();          // adj_...
            $table->string('operator_code')->index();
            $table->string('customer_id')->nullable()->index();
            $table->string('account_id')->nullable()->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('parent_invoice_id')->nullable()->index(); // required for FULL/LINE scope and all DEBIT notes
            $table->string('target_wallet_ref')->nullable();     // PREPAID: direct the note at a specific wallet
            $table->string('billing_mode')->default('POSTPAID'); // POSTPAID | PREPAID — where the note will be applied
            $table->string('direction');                         // CREDIT | DEBIT
            $table->string('scope');                             // FULL | LINE | AMOUNT
            $table->string('line_ref')->nullable();              // LINE scope: the parent invoice line adjusted
            $table->string('service_category_code')->nullable(); // AMOUNT scope: mandatory finance classification
            $table->decimal('amount', 14, 2);                    // always positive; direction carries the sign
            $table->string('currency', 3)->default('KES');
            $table->string('reason_code');
            $table->text('justification')->nullable();
            $table->string('status')->default('PROPOSED')->index(); // PROPOSED | PENDING_APPROVAL | APPROVED | REJECTED | CANCELLED_BY_PROPOSER | APPLIED | APPLICATION_FAILED
            $table->string('proposed_by')->nullable();
            $table->string('note_invoice_id')->nullable()->index();  // the CREDIT_NOTE / DEBIT_NOTE invoice issued on approval
            $table->boolean('limit_overridden')->default(false);     // /override-limit was exercised
            $table->string('failure_reason')->nullable();            // why application failed (retryable)
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // Approval audit trail: one row per decision on a proposal.
        Schema::create('adjustment_approval_step', function (Blueprint $table) {
            $table->id();
            $table->string('adjustment_id')->index();
            $table->unsignedInteger('step_no');
            $table->string('decision');                          // APPROVED | REJECTED | REVISION_REQUESTED
            $table->string('decided_by')->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');
            $table->timestamps();
        });

        // BIL-01-CN-01: append-only ledger of every credit / debit note application.
        // One note may write several rows (invoice + surplus to credit balance, or
        // auto-allocation across several open invoices).
        Schema::create('note_application_ledger', function (Blueprint $table) {
            $table->string('id')->primary();                     // na_...
            $table->string('note_id')->index();                  // the CREDIT_NOTE/DEBIT_NOTE invoice_id issued by GEN-01
            $table->string('note_type');                         // CREDIT | DEBIT
            $table->string('adjustment_request_id')->index();
            $table->string('customer_id')->nullable();
            $table->string('operator_code');
            $table->decimal('note_amount', 14, 2);               // face value of the note (always positive)
            $table->string('currency', 3);
            $table->string('target_kind');                       // INVOICE | WALLET | CREDIT_BALANCE
            $table->string('target_id')->nullable();             // invoice_id / wallet_ref / NULL for credit balance
            $table->decimal('applied_amount', 14, 2);            // what actually moved against this target
            $table->decimal('target_balance_before', 14, 2);
            $table->decimal('target_balance_after', 14, 2);
            $table->string('status');                            // APPLIED | FAILED
            $table->string('failure_reason')->nullable();        // WALLET_INSUFFICIENT_BALANCE | INVOICE_NOT_FOUND | INVOICE_NOT_APPLIABLE | CURRENCY_MISMATCH
            $table->timestamp('applied_at');
            $table->timestamps();
            $table->index(['customer_id', 'applied_at']);
        });

        // One APPLIED row per (note, target) — split applications stay unique while
        // a FAILED attempt may be retried (/retry-application) and re-recorded.
        DB::statement("CREATE UNIQUE INDEX note_application_unique_applied
            ON note_application_ledger (note_id, target_kind, target_id)
            WHERE status = 'APPLIED'");
    }

    public function down(): void
    {
        Schema::dropIfExists('note_application_ledger');
        Schema::dropIfExists('adjustment_approval_step');
        Schema::dropIfExists('adjustment_request');
        Schema::dropIfExists('adjustment_reason_code');
    }
};
