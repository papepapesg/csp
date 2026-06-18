<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Generalise the flat usage tariff (DATA/SMS) into a real rating tariff, so non-voice
 * usage gets the same engine shape as voice: reservation/pulse rounding, an included
 * allowance, setup fee, minimum charge and a charge policy. Defaults keep existing
 * rows behaving exactly as the old flat per-unit rate (increment 1/1, no fee/min/
 * allowance, CHARGEABLE), so nothing changes until an operator configures the richer
 * fields. UsageRatingService consumes these; mediation delegates DATA/SMS to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_tariff', function (Blueprint $table) {
            $table->string('unit_type')->nullable()->after('unit');                       // BYTE | MB | MESSAGE | SECOND
            $table->decimal('initial_increment_units', 14, 4)->default(1)->after('unit_type');     // reservation
            $table->decimal('subsequent_increment_units', 14, 4)->default(1)->after('initial_increment_units'); // pulse
            $table->decimal('setup_fee', 12, 4)->default(0)->after('subsequent_increment_units');
            $table->decimal('min_charge', 12, 4)->default(0)->after('setup_fee');
            $table->decimal('included_units', 14, 4)->default(0)->after('min_charge');      // bundle allowance
            $table->string('charge_policy')->default('CHARGEABLE')->after('included_units'); // CHARGEABLE | ZERO_RATED | BLOCKED
        });

        DB::statement("UPDATE usage_tariff SET unit_type = COALESCE(unit_type, unit) WHERE unit_type IS NULL");
    }

    public function down(): void
    {
        Schema::table('usage_tariff', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'initial_increment_units', 'subsequent_increment_units', 'setup_fee', 'min_charge', 'included_units', 'charge_policy']);
        });
    }
};
