<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-WF-RESTRICT-01 supporting catalogs.
 *
 * - subscription_restriction: the SUB-LM-01-owned restriction catalog. Each row
 *   is a partial-service constraint code (e.g. OUTGOING_VOICE_BARRED) with the
 *   opaque fulfillment_action FUL-04 translates into vendor commands, plus the
 *   self-service / admin-only / active flags the RESTRICT validation snapshots.
 * - subscription_restrict_config: the SUB-WF-RESTRICT-01-owned per-operator
 *   config (self-service enablement, dunning-marker enforcement, approval codes).
 *
 * The active_restrictions[] JSONB array itself is a column already on the
 * subscription master (owned by SUB-LM-01); RESTRICT only mutates that array and
 * never the status_code (R-SUB-WF-RESTRICT-01-S-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // SUB-LM-01 restriction catalog (codes + fulfillment actions).
        Schema::create('subscription_restriction', function (Blueprint $table) {
            $table->string('restriction_id')->primary();        // srest_...
            $table->string('operator_code')->index();
            $table->string('restriction_code');                 // OUTGOING_VOICE_BARRED, ...
            $table->string('name');
            $table->string('fulfillment_action');               // AAA_RESTRICT_OUTGOING_VOICE (FUL-04 input)
            $table->boolean('customer_self_service_eligible')->default(false);
            $table->boolean('admin_only')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['operator_code', 'restriction_code']);
        });

        // SUB-WF-RESTRICT-01 per-operator config (one row per operator).
        Schema::create('subscription_restrict_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->boolean('customer_self_service_enabled')->default(false);
            $table->boolean('dunning_marker_strict')->default(true);
            $table->json('restriction_approval_required_for_codes')->default('[]');
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_restrict_config');
        Schema::dropIfExists('subscription_restriction');
    }
};
