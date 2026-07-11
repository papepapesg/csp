<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-operation operator config catalogs owned by the operation DDs:
 *  - subscription_pause_config (DD_SUB-WF-PAUSE-01 §7.3): operator-level switches
 *    and duration limits for voluntary pause (self-service master switch, scheduled
 *    pause, max future scheduled resume window, minimum pause duration, notify).
 *  - subscription_suspend_np_config (DD_SUB-WF-SUSPEND-NP-01 §3.1): per-operator
 *    customer-notification preference at non-payment suspension + the debt-amount
 *    warning tier (threshold + currency, both-or-neither).
 * One row per operator in each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_pause_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->boolean('customer_self_service_enabled')->default(false);   // master switch for customer-portal pause
            $table->boolean('scheduled_pause_enabled')->default(true);          // allows FIXED_DAYS / SCHEDULED_RESUME_TIME
            $table->unsignedInteger('max_future_scheduled_resume_days')->default(90); // upper bound for scheduled resume
            $table->unsignedInteger('min_pause_hours')->default(24);            // prevents very short pauses
            $table->boolean('customer_notification_enabled')->default(true);    // notify customer on pause success
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('subscription_suspend_np_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->boolean('customer_notification_enabled')->default(true);    // notify customer at NP suspension
            $table->decimal('debt_amount_warning_threshold', 18, 2)->nullable(); // debtAmountTier=HIGH above this
            $table->string('debt_amount_warning_currency')->nullable();         // currency for the threshold
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        // SQLite cannot add a named CHECK constraint after table creation. The
        // application validates the pair on that test/development driver; production
        // databases retain the database-level invariant as a second line of defence.
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE subscription_suspend_np_config
                ADD CONSTRAINT chk_suspend_np_debt_tier
                CHECK ((debt_amount_warning_threshold IS NULL) = (debt_amount_warning_currency IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_suspend_np_config');
        Schema::dropIfExists('subscription_pause_config');
    }
};
