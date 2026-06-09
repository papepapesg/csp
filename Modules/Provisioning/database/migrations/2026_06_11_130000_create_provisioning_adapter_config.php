<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 §10.2 provisioning_adapter_config — maps a provisioning target (and
 * optionally a PLM provisioner_key) to the vendor adapter class that speaks that
 * platform's protocol, plus its execution mode, timeout and retry policy. This is
 * what makes dispatch pick the RIGHT adapter per command/target: a GPON command
 * resolves a Huawei adapter, a voice command a SIP adapter, etc. Without a row the
 * service falls back to the deployment's default driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_adapter_config', function (Blueprint $table) {
            $table->string('adapter_config_id')->primary();    // pac_...
            $table->string('operator_code')->index();
            $table->string('provisioner_key')->nullable();     // PLM service provisioner_key (optional refinement)
            $table->string('target_code')->index();            // FK-by-code to provisioning_target
            $table->string('adapter_class');                   // FQCN implementing ProvisioningAdapter
            $table->string('execution_mode_default')->default('SYNC_REQUIRED'); // SYNC_REQUIRED | ASYNC_ACCEPTED
            $table->unsignedInteger('timeout_ms')->default(25000);
            $table->unsignedInteger('max_retry_count')->default(5);
            $table->json('retry_policy_json')->nullable();     // {baseSeconds, factor}
            $table->string('status')->default('ACTIVE');       // ACTIVE | SUSPENDED
            $table->timestamps();

            $table->unique(['operator_code', 'target_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_adapter_config');
    }
};
