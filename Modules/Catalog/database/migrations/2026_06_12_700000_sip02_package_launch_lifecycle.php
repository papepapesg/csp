<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIP-02 Package Launch Lifecycle (DD §5). Six tables that govern how a SIP-01
 * package version moves from draft to sellable, suspended, end-of-sale, retired:
 *
 *  - package_launch_plan      §5.1 the launch request for one package version.
 *  - package_launch_check     §5.2 validation findings (PASS/WARN/FAIL).
 *  - package_availability     §5.3 where/which channels a version is sellable.
 *  - package_version_cutover  §5.4 current-version changes (new sales only).
 *  - package_lifecycle_event  §5.5 immutable lifecycle audit trail.
 *  - package_retirement_plan  §5.6 controlled end-of-sale / end-of-life plans.
 *
 * String PKs follow the operator-scoped prefixed-id convention (plp_/plc_/pav_/
 * pvc_/ple_/prp_). SIP-02 never modifies the SIP-01 master tables here.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §5.1 — launch plan for one package version.
        Schema::create('package_launch_plan', function (Blueprint $table) {
            $table->string('launch_plan_id')->primary();          // plp_...
            $table->string('operator_code');
            $table->string('package_id');
            $table->string('package_code');
            $table->string('package_version_id');
            $table->string('launch_type');                        // FIRST_LAUNCH|VERSION_CUTOVER|REGION_EXPANSION|RESUME
            $table->timestamp('requested_launch_at')->nullable();
            $table->string('effective_timezone')->nullable();
            $table->string('status')->default('DRAFT');           // lifecycle state (DD §4)
            $table->string('approval_policy_code')->nullable();
            $table->string('approval_request_id')->nullable();
            $table->string('approval_mode')->nullable();          // AUTO|SINGLE_STEP|MULTI_STEP|BPMN
            $table->string('camunda_process_instance_id')->nullable();
            $table->string('requested_by_user_id')->nullable();
            $table->string('approved_by_user_id')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'package_id', 'status']);
            $table->index(['operator_code', 'requested_launch_at', 'status']);
            $table->index(['approval_request_id']);
        });

        // §5.2 — launch validation checks and their results.
        Schema::create('package_launch_check', function (Blueprint $table) {
            $table->string('check_id')->primary();                // plc_...
            $table->string('launch_plan_id');
            $table->string('check_code');                         // e.g. SERVICE_ACTIVE
            $table->string('check_status');                       // PASS|WARN|FAIL
            $table->text('message')->nullable();
            $table->string('source_module_code')->nullable();
            $table->json('source_ref_json')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->index(['launch_plan_id', 'check_status']);
            $table->index(['check_code', 'check_status']);
        });

        // §5.3 — where/which channels a package version is sellable.
        Schema::create('package_availability', function (Blueprint $table) {
            $table->string('availability_id')->primary();         // pav_...
            $table->string('operator_code');
            $table->string('package_id');
            $table->string('package_version_id');
            $table->string('franchise_id')->nullable();           // null = all franchises
            $table->string('tech_region_code')->nullable();       // null = all regions
            $table->string('channel_code');                       // BACKOFFICE|SALES_APP|SELF_CARE|API
            $table->timestamp('available_from')->nullable();
            $table->timestamp('available_until')->nullable();
            $table->string('status')->default('SCHEDULED');       // SCHEDULED|ACTIVE|SUSPENDED|ENDED
            $table->string('reason_code')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'package_id', 'status']);
            $table->index(['operator_code', 'franchise_id', 'tech_region_code', 'channel_code', 'status'], 'pav_scope_lookup');
            $table->index(['operator_code', 'available_from', 'available_until']);
        });

        // §5.4 — current-version change records (price/geo/tax/wallet cutover).
        Schema::create('package_version_cutover', function (Blueprint $table) {
            $table->string('cutover_id')->primary();              // pvc_...
            $table->string('operator_code');
            $table->string('package_id');
            $table->string('from_version_id')->nullable();
            $table->string('to_version_id');
            $table->timestamp('cutover_at')->nullable();
            $table->string('status')->default('SCHEDULED');       // SCHEDULED|COMPLETED|FAILED|CANCELLED
            $table->string('launch_plan_id')->nullable();
            $table->string('executed_by_worker_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'package_id', 'cutover_at']);
            $table->index(['status', 'cutover_at']);
        });

        // §5.5 — immutable lifecycle audit trail.
        Schema::create('package_lifecycle_event', function (Blueprint $table) {
            $table->string('event_id')->primary();                // ple_...
            $table->string('operator_code');
            $table->string('package_id');
            $table->string('package_version_id')->nullable();
            $table->string('launch_plan_id')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('event_type');                         // e.g. PACKAGE_ACTIVATED
            $table->string('reason_code')->nullable();
            $table->string('actor_user_id')->nullable();
            $table->string('source_module_code')->default('SIP-02');
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['operator_code', 'package_id', 'created_at']);
            $table->index(['launch_plan_id', 'created_at']);
        });

        // §5.6 — controlled end-of-sale / end-of-life plans.
        Schema::create('package_retirement_plan', function (Blueprint $table) {
            $table->string('retirement_plan_id')->primary();      // prp_...
            $table->string('operator_code');
            $table->string('package_id');
            $table->string('package_version_id')->nullable();
            $table->string('retirement_type');                    // END_OF_SALE|END_OF_LIFE|RETIRE_VERSION
            $table->timestamp('effective_at')->nullable();
            $table->string('existing_subscriber_policy');         // KEEP_AS_IS|MIGRATE_REQUIRED|BLOCK_RENEWAL
            $table->string('migration_workflow_ref')->nullable();
            $table->string('approval_request_id')->nullable();
            $table->string('status')->default('DRAFT');           // DRAFT|PENDING_APPROVAL|APPROVED|SCHEDULED|COMPLETED|CANCELLED
            $table->string('reason_code')->nullable();
            $table->string('created_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'package_id', 'status']);
            $table->index(['status', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_retirement_plan');
        Schema::dropIfExists('package_lifecycle_event');
        Schema::dropIfExists('package_version_cutover');
        Schema::dropIfExists('package_availability');
        Schema::dropIfExists('package_launch_check');
        Schema::dropIfExists('package_launch_plan');
    }
};
