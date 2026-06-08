<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SUB-WF-PAUSE-01 §7.2 / SUB-LM-01 subscription_pause_history. Records every
 * suspension-like period that RESUME must understand: PAUSE writes voluntary/admin
 * rows, SUSPEND-NP writes non-payment rows, RESUME closes the open row. At most one
 * open row per subscription (R-PAUSE-S-3). Resume reads the open row to decide
 * eligibility (R-RESUME-OI-1/2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_pause_history', function (Blueprint $table) {
            $table->string('pause_id')->primary();              // pause_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->index();
            $table->string('customer_id')->nullable();
            $table->string('origin_intent');                    // CUSTOMER_REQUESTED_PAUSE | ADMIN_PAUSE | SUSPEND_NP
            $table->boolean('system_managed')->default(false);  // true only for SUSPEND_NP
            $table->string('pause_reason_code')->nullable();
            $table->string('duration_mode')->nullable();        // OPEN_ENDED | FIXED_DAYS | SCHEDULED_RESUME_TIME
            $table->unsignedInteger('duration_days')->nullable();
            $table->timestamp('resume_scheduled_at')->nullable();
            $table->timestamp('suspended_at');
            $table->timestamp('actual_resume_at')->nullable();  // null == open
            $table->string('resume_reason_code')->nullable();
            $table->string('resume_actor_user_id')->nullable();
            $table->string('resume_actor_role')->nullable();
            $table->boolean('admin_force_resume')->default(false);
            $table->string('dunning_reason_code')->nullable();
            $table->string('dunning_cycle_reference')->nullable();
            $table->unsignedInteger('dunning_escalation_level')->nullable();
            $table->decimal('outstanding_debt_amount', 18, 2)->nullable();
            $table->string('outstanding_debt_currency', 3)->nullable();
            $table->string('pause_correlation_id')->nullable();  // operation id
            $table->string('pause_actor_user_id')->nullable();
            $table->string('pause_actor_role')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // R-PAUSE-S-3: at most one open pause/suspension row per subscription.
        DB::statement('CREATE UNIQUE INDEX uq_open_pause_per_subscription
            ON subscription_pause_history (subscription_id) WHERE actual_resume_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_open_pause_per_subscription');
        Schema::dropIfExists('subscription_pause_history');
    }
};
