<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-LM-01 §5.2/5.3 lifecycle catalogs.
 *
 * - subscription_status_code: the authoritative status vocabulary + semantic
 *   flags (is_active/is_billable/is_terminal/is_pending/allows_*). Every
 *   subscription.status_code must exist here. Includes the per-operation transient
 *   PENDING_* states (entered only during a saga's commit window).
 * - subscription_transition_reason: the reason-code catalog so every status change
 *   carries a structured business reason instead of free text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_status_code', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('is_billable')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_pending')->default(false);
            $table->boolean('allows_package_change')->default(false);
            $table->boolean('allows_address_move')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('subscription_transition_reason', function (Blueprint $table) {
            $table->string('reason_code')->primary();
            $table->string('transition_type');                  // PAUSE | RESUME | SUSPEND_NP | TERMINATE | ...
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_transition_reason');
        Schema::dropIfExists('subscription_status_code');
    }
};
