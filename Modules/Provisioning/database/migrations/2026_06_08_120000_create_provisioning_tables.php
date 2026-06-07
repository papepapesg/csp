<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 Provisioning Broadcast & Reconciliation. The shared layer that
 * sends technical commands to network/service platforms, tracks execution,
 * retries, and reconciles desired vs observed state. PROV-INT does not decide
 * which subscription state is correct; it dispatches commands for the desired
 * state supplied by owning modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Provisioning target = an external system we send commands to.
        Schema::create('provisioning_target', function (Blueprint $table) {
            $table->string('target_code')->primary();          // HUAWEI_NCE_GPON_KE
            $table->string('operator_code')->index();
            $table->string('type');                            // GPON | HFC | VOIP | NMS
            $table->string('name');
            $table->string('endpoint')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // One technical instruction to one target (idempotent ledger).
        Schema::create('provisioning_command', function (Blueprint $table) {
            $table->string('command_id')->primary();           // pcmd_...
            $table->string('operator_code')->index();
            $table->string('broadcast_id')->nullable()->index(); // groups commands from one action
            $table->string('subscription_id')->nullable()->index();
            $table->string('service_ref')->nullable();
            $table->string('action');                          // ACTIVATE|MODIFY|DEACTIVATE|SUSPEND|RESUME
            $table->string('target_code')->index();
            $table->json('desired_state')->nullable();
            $table->json('observed_state')->nullable();
            $table->string('status')->default('PENDING')->index(); // PENDING|SENT|CONFIRMED|FAILED|MISMATCH
            $table->string('external_ref')->nullable();        // id returned by the target
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error')->nullable();
            $table->string('correlation_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_command');
        Schema::dropIfExists('provisioning_target');
    }
};
