<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * REP-01 reporting data mart. REP owns reporting read models fed by domain
 * events; it must not own operational write state, and dashboards read only from
 * here, never fanning out to operational modules (MVP baseline §1.5, HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_daily_metric', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code')->index();
            $table->date('metric_date')->index();
            $table->string('metric_key')->index();             // subscriptions_activated, payments_amount, ...
            $table->decimal('value', 18, 2)->default(0);
            $table->timestamps();

            $table->unique(['operator_code', 'metric_date', 'metric_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_daily_metric');
    }
};
