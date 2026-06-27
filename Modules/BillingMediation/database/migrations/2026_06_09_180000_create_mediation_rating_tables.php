<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MED-01 Mediation + RAT-01 Rating. usage_record is a mediated usage event (CDR:
 * voice seconds / data MB / SMS count) deduplicated by source_ref. rated_event is
 * the priced result — RAT-01 resolves the tariff (PLM-CFG-07 voice tariffs by
 * destination, or a flat data rate) and computes the charge, which BIL-01 bills.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_record', function (Blueprint $table) {
            $table->string('usage_id')->primary();              // use_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('account_id')->nullable()->index();
            $table->string('usage_type');                       // VOICE | DATA | SMS
            $table->string('destination')->nullable();          // ONNET | OFFNET | INTERNATIONAL
            $table->decimal('quantity', 16, 4);                 // seconds | MB | count
            $table->string('source_ref')->index();              // dedupe key (CDR id)
            $table->timestamp('occurred_at')->nullable();
            $table->string('status')->default('RECEIVED')->index(); // RECEIVED | RATED | REJECTED
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'source_ref']);
        });

        Schema::create('rated_event', function (Blueprint $table) {
            $table->string('rated_id')->primary();              // rat_...
            $table->string('operator_code')->index();
            $table->string('usage_id')->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('tariff_code')->nullable();
            $table->decimal('rate', 12, 4)->default(0);
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->boolean('billed')->default(false);
            $table->timestamps();

            $table->foreign('usage_id')->references('usage_id')->on('usage_record')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rated_event');
        Schema::dropIfExists('usage_record');
    }
};
