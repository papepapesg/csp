<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 §10.9 provisioning_force_sync_request — NOC's approved corrective
 * action when desired ≠ observed. R-PROV-07: force-sync (a potentially destructive
 * re-push to the network) requires approval before execution and is audited. The
 * request moves PENDING_APPROVAL → APPROVED → RUNNING → COMPLETED (or CANCELLED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_force_sync_request', function (Blueprint $table) {
            $table->string('force_sync_id')->primary();         // pfs_...
            $table->string('operator_code')->index();
            $table->string('source_item_id')->nullable()->index(); // reconciliation item that triggered it
            $table->string('subscription_id')->nullable()->index();
            $table->string('service_ref')->nullable();
            $table->string('target_code')->index();
            $table->string('sync_direction')->default('BSS_TO_NETWORK'); // BSS_TO_NETWORK | NETWORK_TO_BSS | MARK_IGNORE
            $table->string('requested_action')->nullable();     // e.g. REAPPLY_PROFILE
            $table->string('status')->default('PENDING_APPROVAL')->index(); // PENDING_APPROVAL|APPROVED|RUNNING|COMPLETED|FAILED|CANCELLED
            $table->string('requested_by_user_id')->nullable();
            $table->string('approved_by_user_id')->nullable();
            $table->string('command_id')->nullable();           // corrective provisioning command
            $table->string('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_force_sync_request');
    }
};
