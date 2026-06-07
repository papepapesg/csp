<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WO-01 framework catalogs for the SUPPORT (and SHIFTING) satellite flows.
 *
 * - wo_job_type_catalog: per-operator job-type codes with their site-visit
 *   requirement, warranty window and network type (WO-01-FLOW-SUPPORT §3.2). The
 *   site-visit-decision gateway reads requires_site_visit; the warranty-linkage
 *   worker reads warranty_days.
 * - wo_flow_config: maps an operator + WO kind to the process key + rules ref so
 *   a market swaps flow as config (WO-01-FLOW-SUPPORT §3.1).
 *
 * Also extends work_order with the flow's control fields (kind, job_type_code,
 * current_phase, final_reason, master_wo_id, initial_reason, escalation flag).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wo_job_type_catalog', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code')->index();
            $table->string('job_type_code');                    // GP3, HS3, QCS, RPT, ...
            $table->string('kind');                             // SUPPORT | SHIFTING | INSTALLATION
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->string('network_type')->nullable();         // GPON | HFC | ...
            $table->boolean('requires_site_visit')->default(true);
            $table->unsignedInteger('warranty_days')->default(90);
            $table->timestamps();

            $table->unique(['operator_code', 'job_type_code']);
        });

        Schema::create('wo_flow_config', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code')->index();
            $table->string('kind');                             // SUPPORT | SHIFTING | INSTALLATION
            $table->string('bpmn_process_key');                 // wo-support (engine process key)
            $table->string('drools_kjar_ref')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'kind']);
        });

        Schema::table('work_order', function (Blueprint $table) {
            $table->string('kind')->nullable()->after('type');          // SUPPORT | SHIFTING | ...
            $table->string('job_type_code')->nullable()->after('kind');
            $table->string('current_phase')->nullable()->after('job_type_code');
            $table->string('final_reason')->nullable()->after('resolution_code');
            $table->string('master_wo_id')->nullable()->index()->after('source_ref'); // RPT/QCS link to original
            $table->string('originating_context_type')->nullable()->after('master_wo_id'); // TICKET | ...
            $table->text('initial_reason')->nullable()->after('originating_context_type');
            $table->boolean('escalation_candidate')->default(false)->after('initial_reason');
            $table->timestamp('warranty_until')->nullable()->after('finalized_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropColumn([
                'kind', 'job_type_code', 'current_phase', 'final_reason', 'master_wo_id',
                'originating_context_type', 'initial_reason', 'escalation_candidate', 'warranty_until',
            ]);
        });
        Schema::dropIfExists('wo_flow_config');
        Schema::dropIfExists('wo_job_type_catalog');
    }
};
