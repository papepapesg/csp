<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 §10.4 provisioning_command_attempt — one row per dispatch attempt for a
 * command (R-PROV-01/05: stored before/around dispatch, supports retry + vendor-error
 * debugging). Records which adapter ran, the outcome, vendor status, and duration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_command_attempt', function (Blueprint $table) {
            $table->string('attempt_id')->primary();            // pcma_...
            $table->string('operator_code')->index();
            $table->string('command_id')->index();
            $table->unsignedInteger('attempt_no');
            $table->string('adapter_class')->nullable();        // which vendor adapter ran
            $table->string('status');                           // SUCCESS | FAILED_RETRYABLE | FAILED_FINAL | TIMEOUT
            $table->json('response_payload')->nullable();
            $table->string('vendor_status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['command_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_command_attempt');
    }
};
