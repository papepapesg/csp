<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-03 cycle-window state on the subscription master (SUB-LM owns the cycle
 * anchor; BIL-03 reads + advances it). The cycle *attributes* (cycle_model,
 * cycle_anchor_day, cycle_period_days, cycle_frequency_months) already exist;
 * these are the moving boundary the cycle-close scanner evaluates each pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription', function (Blueprint $table) {
            $table->timestamp('current_cycle_start')->nullable()->after('cycle_frequency_months');
            $table->timestamp('current_cycle_end')->nullable()->after('current_cycle_start');
            // Idempotency key (R-BIL-03-C-5): the last window already closed, so a
            // re-run of the scanner never double-bills the same cycle.
            $table->timestamp('last_cycle_closed_window_end')->nullable()->after('current_cycle_end');
            // PREPAYMENT: the invoice for the UPCOMING cycle, checked at boundary.
            $table->string('next_cycle_charge_invoice_id')->nullable()->after('last_cycle_closed_window_end');
            $table->index(['status_code', 'current_cycle_end']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription', function (Blueprint $table) {
            $table->dropIndex(['status_code', 'current_cycle_end']);
            $table->dropColumn(['current_cycle_start', 'current_cycle_end', 'last_cycle_closed_window_end', 'next_cycle_charge_invoice_id']);
        });
    }
};
