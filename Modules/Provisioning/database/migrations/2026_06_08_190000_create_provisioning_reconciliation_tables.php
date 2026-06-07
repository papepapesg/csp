<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 reconciliation data model (§10.5-10.8). Desired state is what BSS
 * believes should exist on the network; observed state is what a target reports;
 * a reconciliation run compares them and records each mismatch as an item for NOC
 * review / force-sync. Mismatches never auto-fix by default (R-PROV-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §10.5 BSS desired technical state per subscription service.
        Schema::create('provisioning_desired_state', function (Blueprint $table) {
            $table->string('desired_state_id')->primary();      // pds_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->index();
            $table->string('customer_id')->nullable();
            $table->string('homepass_id')->nullable();
            $table->string('service_ref')->nullable();
            $table->string('target_code')->index();
            $table->string('subscriber_key');                   // target lookup key
            $table->string('desired_status');                   // ACTIVE|SUSPENDED|RESTRICTED|TERMINATED|NOT_PRESENT
            $table->json('desired_profile')->nullable();
            $table->string('source_module')->nullable();
            $table->string('source_ref')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'subscription_id', 'service_ref', 'target_code'], 'pds_unique');
        });

        // §10.6 latest observed state fetched from a target.
        Schema::create('provisioning_observed_state', function (Blueprint $table) {
            $table->string('observed_state_id')->primary();     // pos_...
            $table->string('operator_code')->index();
            $table->string('target_code')->index();
            $table->string('subscriber_key');
            $table->string('observed_status')->nullable();
            $table->json('observed_profile')->nullable();
            $table->string('source_run_id')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();

            $table->unique(['target_code', 'subscriber_key'], 'pos_unique');
        });

        // §10.7 one reconciliation run against a target (or all targets).
        Schema::create('provisioning_reconciliation_run', function (Blueprint $table) {
            $table->string('run_id')->primary();                // prr_...
            $table->string('operator_code')->nullable()->index();
            $table->string('target_code')->nullable()->index(); // null = all targets
            $table->string('scope_type')->default('FULL_TARGET'); // FULL_TARGET|REGION|SUBSCRIPTION|SERVICE_CLASS
            $table->string('scope_value')->nullable();
            $table->string('status')->default('RUNNING')->index(); // RUNNING|COMPLETED|FAILED|PARTIAL
            $table->unsignedInteger('desired_count')->default(0);
            $table->unsignedInteger('observed_count')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // §10.8 one desired-vs-observed mismatch.
        Schema::create('provisioning_reconciliation_item', function (Blueprint $table) {
            $table->string('item_id')->primary();               // pri_...
            $table->string('operator_code')->index();
            $table->string('run_id')->index();
            $table->string('target_code')->index();
            $table->string('subscription_id')->nullable();
            $table->string('service_ref')->nullable();
            $table->string('subscriber_key');
            $table->string('desired_status')->nullable();
            $table->string('observed_status')->nullable();
            $table->json('diff')->nullable();
            $table->string('status')->default('OPEN')->index(); // OPEN|RESOLVED|IGNORED
            $table->string('resolution')->nullable();           // FORCE_SYNCED|MANUAL|MATCHED_SINCE
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_reconciliation_item');
        Schema::dropIfExists('provisioning_reconciliation_run');
        Schema::dropIfExists('provisioning_observed_state');
        Schema::dropIfExists('provisioning_desired_state');
    }
};
