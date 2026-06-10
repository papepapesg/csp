<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-03 cycle-close run audit (R-BIL-03-E-5): one row per scanner pass, with
 * the counts an operator's Cycle Close console reads. Failed subscriptions are
 * retried on the next pass; persistent failures are surfaced for review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_close_run', function (Blueprint $table) {
            $table->string('run_id')->primary();                 // ccr_...
            $table->string('operator_code')->index();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('subscriptions_evaluated')->default(0);
            $table->unsignedInteger('subscriptions_closed')->default(0);
            $table->unsignedInteger('subscriptions_skipped')->default(0);
            $table->unsignedInteger('subscriptions_failed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_close_run');
    }
};
