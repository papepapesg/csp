<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WO-01-FRAMEWORK alignment: brings the Work Order module up to the DD's framework
 * contract that every flow builds on —
 *  - wo_assignment_history (§1.7): reassign is a first-class op (ASSIGNED→ASSIGNED,
 *    IN_PROGRESS→IN_PROGRESS) logged here; status does not change.
 *  - structured notes (§1.3): wo_note gains note_kind + payload; wo_note_kind_registry
 *    holds the per-operator JSON schemas notes are validated against.
 *  - wo_finalization_requirements (§4.4): the per-(operator,kind,job_type) checklist
 *    the 2-step finalize enforces at second-confirm.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §1.7 reassignment audit (contractor/team/tech changes without a status change).
        Schema::create('wo_assignment_history', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('work_order_id')->index();
            $table->string('prev_contractor_id')->nullable();
            $table->string('prev_team_id')->nullable();
            $table->string('prev_assigned_technician_id')->nullable();
            $table->string('contractor_id')->nullable();
            $table->string('team_id')->nullable();
            $table->string('assigned_technician_id')->nullable();
            $table->string('reason')->nullable();             // e.g. PATTERN_B install->maintenance
            $table->string('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();
            $table->timestamps();

            $table->foreign('work_order_id')->references('work_order_id')->on('work_order')->cascadeOnDelete();
        });

        // §1.3 structured notes: typed, optionally schema-validated payload.
        Schema::table('wo_note', function (Blueprint $table) {
            $table->string('note_kind')->default('note')->after('work_order_id'); // FK-by-code to registry
            $table->json('payload')->nullable()->after('body');
            $table->text('body')->nullable()->change();       // structured notes may carry no free text
        });

        // §4.3 per-operator note-kind catalog (the JSON schema each kind validates against).
        Schema::create('wo_note_kind_registry', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('note_kind');
            $table->json('schema_jsonb')->nullable();          // null = free-text, no validation
            $table->boolean('append_only')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'note_kind']);
        });

        // §4.4 finalize checklist: what must be present before second-confirm succeeds.
        Schema::create('wo_finalization_requirements', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code');
            $table->string('kind');                            // INSTALLATION | SUPPORT | SHIFTING
            $table->string('job_type_code')->nullable();       // null = default for the kind
            $table->json('required_note_kinds');               // e.g. ["findings","solution","final_reason_set"]
            $table->json('required_attachment_categories')->nullable();
            $table->json('min_attachments_per_category')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'kind', 'job_type_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_finalization_requirements');
        Schema::dropIfExists('wo_note_kind_registry');
        Schema::table('wo_note', function (Blueprint $table) {
            $table->dropColumn(['note_kind', 'payload']);
        });
        Schema::dropIfExists('wo_assignment_history');
    }
};
