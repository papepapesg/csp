<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-WF-UPGRADE-01 / SUB-WF-DOWNGRADE-01 per-operator config (one row per
 * operator + kind). Controls self-service eligibility, the pay-first gate, the
 * default cycle-anchor policy + effective-timing, and the scheduled-future
 * window. Effective timing + cycle effects apply at the actual commit time, not
 * the request time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_upgrade_config', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('kind')->default('UPGRADE');         // UPGRADE | DOWNGRADE
            $table->boolean('customer_self_service_enabled')->default(false);
            $table->boolean('pay_first_required')->default(true);
            $table->string('default_cycle_anchor_policy')->default('PRESERVE'); // PRESERVE | RESET_TO_UPGRADE_DATE
            $table->string('default_effective_timing')->default('IMMEDIATE');   // IMMEDIATE | END_OF_CURRENT_CYCLE | SCHEDULED_AT
            $table->unsignedInteger('max_future_scheduled_days')->default(90);
            $table->boolean('customer_notification_enabled')->default(true);
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->primary(['operator_code', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_upgrade_config');
    }
};
