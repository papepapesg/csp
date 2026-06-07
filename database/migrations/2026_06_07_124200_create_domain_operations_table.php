<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic long-running operation ledger (FOUNDATION_CAMUNDA, native driver).
 *
 * This is the Laravel-native equivalent of a Camunda process instance record:
 * every asynchronous/long-running command creates an operation row that tracks
 * idempotency, status, the owning workflow, current step and result. Module
 * workflow tables (e.g. SUB-WF operations) build on the same contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id')->unique();      // op_01J...
            $table->string('operation_type')->index();     // e.g. SUBSCRIPTION_ACTIVATE
            $table->string('workflow')->nullable();        // workflow class / process key
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable()->index();
            $table->string('operator_code')->nullable()->index();
            $table->string('correlation_id')->nullable();
            $table->string('idempotency_key')->nullable()->index();
            $table->string('request_hash', 64)->nullable();
            $table->string('status')->default('PENDING')->index(); // PENDING|RUNNING|COMPLETED|FAILED|CANCELLED
            $table->string('current_step')->nullable();
            $table->string('process_instance_id')->nullable(); // camunda ref when driver=camunda
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_operations');
    }
};
