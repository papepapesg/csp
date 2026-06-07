<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-LM-01 subscription master + SUB-WF-FRAMEWORK operation ledger.
 *
 * SUB-LM owns the subscription row and lifecycle fields. SUB-WF owns the
 * operation ledger (idempotency, concurrency, workflow correlation). The two are
 * separate so workflow complexity never corrupts master-data ownership (HLD §6.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SUB-LM-01 — subscription master.
        Schema::create('subscription', function (Blueprint $table) {
            $table->string('subscription_id')->primary();      // sub_...
            $table->string('customer_id')->index();            // denormalized identity (ILM)
            $table->string('account_id')->index();             // operational anchor (ILM)
            $table->string('operator_code')->index();
            $table->string('homepass_id');                     // RLM
            $table->string('previous_homepass_id')->nullable();
            $table->string('package_ref');                     // catalog package id
            $table->string('package_version_id')->nullable();
            $table->string('previous_package_ref')->nullable();
            $table->string('previous_package_version_id')->nullable();
            $table->string('status_code')->default('PENDING_ACTIVATION')->index();
            $table->string('billing_mode')->default('POSTPAID'); // POSTPAID | PREPAID
            $table->string('currency', 3)->default('KES');
            $table->string('cycle_model')->default('CALENDAR'); // CALENDAR | ANNIVERSARY
            $table->unsignedTinyInteger('cycle_anchor_day')->nullable();
            $table->unsignedInteger('cycle_period_days')->nullable();
            $table->unsignedTinyInteger('cycle_frequency_months')->default(1);
            $table->json('active_restrictions')->default('[]');
            $table->string('current_transition_type')->nullable();
            $table->string('current_transition_reason_code')->nullable();
            $table->json('last_failure')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_status_changed_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        // SUB-WF-FRAMEWORK — operation ledger (one row per lifecycle/MACD operation).
        Schema::create('subscription_operation', function (Blueprint $table) {
            $table->string('operation_id')->primary();         // op_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->index();
            $table->string('operation_kind');                  // ACTIVATE|PAUSE|RESUME|TERMINATE|RESTRICT|...
            $table->string('bpmn_process_key')->nullable();    // workflow class / process key
            $table->string('bpmn_process_instance_id')->nullable();
            $table->string('initiating_actor_user_id')->nullable();
            $table->string('initiating_actor_role')->nullable();
            $table->string('idempotency_key');
            $table->string('idempotency_request_hash', 64);
            $table->string('correlation_id')->nullable();
            $table->string('prior_subscription_status')->nullable();
            $table->string('current_state')->default('PENDING'); // PENDING|RUNNING|COMPLETED|FAILED|CANCELLED
            $table->string('final_state')->nullable();
            $table->string('failure_reason_code')->nullable();
            $table->text('failure_reason_detail')->nullable();
            $table->string('cancel_reason_code')->nullable();
            $table->json('input')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->foreign('subscription_id')->references('subscription_id')->on('subscription');
            $table->unique(['operator_code', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_operation');
        Schema::dropIfExists('subscription');
    }
};
