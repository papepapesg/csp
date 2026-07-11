<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-07 usage_tariff — per-operator flat per-unit rates for non-voice usage
 * (DATA per MB, SMS per message). Replaces the hardcoded rating constants so data/SMS
 * pricing is configuration, like the voice_tariff catalog. RAT-01 reads this at
 * rating time; falls back to a built-in default only when a row is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_tariff', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('usage_type');                       // DATA | SMS
            $table->decimal('rate_per_unit', 12, 4);            // per MB / per message
            $table->string('unit')->nullable();                 // MB | MESSAGE (informational)
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'usage_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_tariff');
    }
};
