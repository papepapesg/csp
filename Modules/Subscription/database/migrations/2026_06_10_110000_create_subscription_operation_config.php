<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-WF-FRAMEWORK-01 §6.3 subscription_operation_config — the per-operator,
 * per-kind orchestration config: which BPMN/process key to start, operation and
 * billing/fulfillment timeouts, feature flags, and whether the kind is enabled.
 * R-SUB-WF-FW-7: the process key is resolved from here. Also adds the framework
 * concurrency indexes (R-SUB-WF-FW-1/2) and the missing cancel actor column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_operation_config', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('operation_kind');
            $table->string('default_bpmn_process_key');
            $table->unsignedInteger('operation_timeout_seconds')->default(90);
            $table->unsignedInteger('billing_call_timeout_seconds')->default(30);
            $table->unsignedInteger('fulfillment_call_timeout_seconds')->default(60);
            $table->json('feature_flags')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['operator_code', 'operation_kind']);
        });

        Schema::table('subscription_operation', function (Blueprint $table) {
            $table->string('cancel_actor_user_id')->nullable()->after('cancel_reason_code');
        });

        // R-SUB-WF-FW-1: at most one in-flight state-changing (non-RESTRICT) operation
        // per (operator, subscription). R-SUB-WF-FW-2: one in-flight RESTRICT allowed
        // alongside. Enforced as partial unique indexes on final_state IS NULL.
        DB::statement("CREATE UNIQUE INDEX uq_subop_in_flight_state_changing
            ON subscription_operation (operator_code, subscription_id)
            WHERE final_state IS NULL AND operation_kind <> 'RESTRICT'");
        DB::statement("CREATE UNIQUE INDEX uq_subop_in_flight_restrict
            ON subscription_operation (operator_code, subscription_id)
            WHERE final_state IS NULL AND operation_kind = 'RESTRICT'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_subop_in_flight_state_changing');
        DB::statement('DROP INDEX IF EXISTS uq_subop_in_flight_restrict');
        Schema::table('subscription_operation', fn (Blueprint $t) => $t->dropColumn('cancel_actor_user_id'));
        Schema::dropIfExists('subscription_operation_config');
    }
};
