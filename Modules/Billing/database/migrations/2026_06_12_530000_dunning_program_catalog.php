<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-04 authoritative model: the versioned `dunning_program` catalog (the operator policy
 * surface — level_definitions JSONB drives grace periods + per-level actions) replaces the
 * decision-table approach; `dunning_state_archive` for terminated/cleared episodes (D-4);
 * and the program-pinning + bookkeeping columns the DD's dunning_state carries.
 */
return new class extends Migration
{
    public function up(): void
    {
        // dunning_program — versioned catalog (C-1/C-2/C-3/C-4).
        Schema::create('dunning_program', function (Blueprint $table) {
            $table->string('id')->primary();                 // dprg_...
            $table->string('code');                          // wananchi_ke_postpaid_standard
            $table->integer('version')->default(1);
            $table->text('description')->nullable();
            $table->string('operator_code');                 // WIK / WANANCHI_KE / ... / *
            $table->string('billing_mode');                  // POSTPAID | PREPAID | PREPAYMENT
            $table->json('level_definitions');               // ordered escalation steps (see DD §level definition schema)
            $table->boolean('pre_termination_review_required')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version']);
            $table->index(['operator_code', 'billing_mode', 'retired_at'], 'dprg_lookup');
        });

        // Pinning + bookkeeping on the live state row.
        Schema::table('dunning_state', function (Blueprint $table) {
            $table->string('dunning_program_ref')->nullable()->after('billing_mode');     // dunning_program.code
            $table->integer('dunning_program_version')->nullable()->after('dunning_program_ref'); // pinned at entry (C-1)
            $table->timestamp('entered_dunning_at')->nullable()->after('entered_level_at'); // when the episode began (level 1)
            $table->json('applied_restriction_codes')->nullable()->after('triggering_event_type'); // R-4 recovery bookkeeping
            $table->string('triggering_event_ref')->nullable()->after('triggering_event_type');    // invoice id / cycle ref
            $table->string('outstanding_debt_currency')->nullable()->after('outstanding_debt_amount');
            $table->timestamp('last_workflow_failure_at')->nullable()->after('last_workflow_failure_code');
            $table->integer('workflow_failure_attempts')->default(0)->after('last_workflow_failure_at'); // WF-3 backoff counter
            $table->timestamp('cleared_at')->nullable()->after('workflow_failure_attempts');
            $table->timestamp('archived_at')->nullable()->after('cleared_at');
        });

        // dunning_state_archive — same shape + archive_reason (D-4). Append-only, P10Y.
        Schema::create('dunning_state_archive', function (Blueprint $table) {
            $table->string('dunning_id')->primary();
            $table->string('operator_code')->index();
            $table->string('account_id')->index();
            $table->string('subscription_id')->nullable();
            $table->string('billing_mode')->nullable();
            $table->string('dunning_program_ref')->nullable();
            $table->integer('dunning_program_version')->nullable();
            $table->integer('current_level')->default(0);
            $table->decimal('outstanding_debt_amount', 15, 2)->default(0);
            $table->string('outstanding_debt_currency')->nullable();
            $table->string('triggering_event_type')->nullable();
            $table->string('triggering_event_ref')->nullable();
            $table->json('applied_restriction_codes')->nullable();
            $table->string('status')->nullable();
            $table->string('archive_reason');                // CLEARED_FULLY_PAID | TERMINATED | ADMIN_CLEARED | SUPERSEDED
            $table->timestamp('entered_dunning_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('archived_at');
            $table->json('snapshot')->nullable();            // full row snapshot for audit
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_state_archive');
        Schema::table('dunning_state', function (Blueprint $table) {
            $table->dropColumn([
                'dunning_program_ref', 'dunning_program_version', 'entered_dunning_at', 'applied_restriction_codes',
                'triggering_event_ref', 'outstanding_debt_currency', 'last_workflow_failure_at', 'workflow_failure_attempts',
                'cleared_at', 'archived_at',
            ]);
        });
        Schema::dropIfExists('dunning_program');
    }
};
