<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-07 Voice Tariff Catalog — the seven configuration tables per DD §7
 * (plan, zone, prefix, time band, rate, allowance, binding). These supply the
 * tariff data RAT-01 reads at rating time; PLM-CFG-07 never rates a CDR itself.
 *
 * Distinct from the legacy `voice_tariff` PLM config table (Models\VoiceTariff):
 * these use the DD names (voice_tariff_plan, voice_destination_zone, ...).
 * String PKs (prefixed-ULID), DECIMAL(18,6) money, effective-dated rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 7.1 voice_tariff_plan — commercial voice tariff plan (versioned, effective-dated).
        Schema::create('voice_tariff_plan', function (Blueprint $table) {
            $table->string('tariff_plan_id')->primary();          // vtp_...
            $table->string('operator_code');                      // R-VOICE-TAR-01
            $table->string('tariff_plan_code');                   // stable code used by packages/rating
            $table->string('display_name');
            $table->string('billing_mode');                       // PREPAID | POSTPAID | BOTH
            $table->string('currency_code', 3);                   // R-VOICE-TAR-11
            $table->string('status')->default('DRAFT');           // DRAFT | ACTIVE | RETIRED
            $table->timestamp('effective_from');                  // R-VOICE-TAR-02
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'tariff_plan_code']);
            $table->index(['operator_code', 'status', 'effective_from']);
        });

        // 7.2 voice_destination_zone — rating zones for voice destinations.
        Schema::create('voice_destination_zone', function (Blueprint $table) {
            $table->string('zone_id')->primary();                 // vdz_...
            $table->string('operator_code');
            $table->string('zone_code');                          // KE_MOBILE, EMERGENCY, ...
            $table->string('zone_name');
            $table->string('zone_type');                          // ON_NET|NATIONAL|REGIONAL|INTERNATIONAL|TOLL_FREE|EMERGENCY|PREMIUM
            $table->string('default_charge_policy');              // CHARGEABLE|ZERO_RATED|BLOCKED|QUARANTINE
            $table->string('status')->default('ACTIVE');          // ACTIVE | RETIRED
            $table->timestamps();

            $table->unique(['operator_code', 'zone_code']);
            $table->index(['operator_code', 'zone_type', 'status']);
        });

        // 7.3 voice_destination_prefix — normalized prefix → zone, longest-prefix match.
        Schema::create('voice_destination_prefix', function (Blueprint $table) {
            $table->string('prefix_id')->primary();               // vdp_...
            $table->string('operator_code');
            $table->string('prefix');                             // +2547, 999, ...
            $table->string('zone_id');                            // FK voice_destination_zone
            $table->integer('match_priority')->default(0);        // tie-break for equal-length prefixes
            $table->string('status')->default('ACTIVE');          // ACTIVE | RETIRED
            $table->timestamp('effective_from');                  // R-VOICE-TAR-02
            $table->timestamp('effective_to')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'status', 'effective_from']);
            $table->index(['operator_code', 'prefix']);
        });

        // 7.4 voice_time_band — time-of-day / day-of-week bands used by rates.
        Schema::create('voice_time_band', function (Blueprint $table) {
            $table->string('time_band_id')->primary();            // vtb_...
            $table->string('operator_code');
            $table->string('time_band_code');                     // ANYTIME, PEAK, OFFPEAK
            $table->string('display_name');
            $table->string('days_of_week');                       // MON,TUE,... (comma-separated)
            $table->time('start_time_local');
            $table->time('end_time_local');
            $table->string('timezone');                           // Africa/Nairobi
            $table->string('status')->default('ACTIVE');          // ACTIVE | RETIRED
            $table->timestamps();

            $table->unique(['operator_code', 'time_band_code']);
        });

        // 7.5 voice_tariff_rate — actual price for a plan, zone, call type, time band.
        Schema::create('voice_tariff_rate', function (Blueprint $table) {
            $table->string('rate_id')->primary();                 // vtr_...
            $table->string('operator_code');
            $table->string('tariff_plan_id');                     // FK voice_tariff_plan
            $table->string('zone_id');                            // FK voice_destination_zone
            $table->string('time_band_id');                       // FK voice_time_band
            $table->string('call_direction');                     // OUTBOUND | INBOUND | FORWARDED
            $table->string('unit_type');                          // SECOND | MINUTE | CALL
            $table->decimal('unit_price', 18, 6);
            $table->decimal('setup_fee_amount', 18, 6)->default(0);
            $table->integer('initial_increment_seconds')->default(60);   // R-VOICE-TAR-09
            $table->integer('subsequent_increment_seconds')->default(60);
            $table->decimal('minimum_charge_amount', 18, 6)->default(0);
            $table->string('charge_policy')->default('CHARGEABLE'); // CHARGEABLE|ZERO_RATED|BLOCKED|QUARANTINE
            $table->string('taxable_kind')->nullable();           // tax lookup kind for PLM-CFG-02
            $table->string('taxable_ref')->nullable();
            $table->timestamp('effective_from');                  // R-VOICE-TAR-02
            $table->timestamp('effective_to')->nullable();
            $table->string('status')->default('DRAFT');           // DRAFT | ACTIVE | RETIRED
            $table->timestamps();

            $table->index(['tariff_plan_id', 'zone_id', 'time_band_id', 'effective_from'], 'vtr_plan_zone_band_eff_idx');
            $table->index(['operator_code', 'status', 'effective_from']);
        });

        // 7.6 voice_tariff_allowance — included / bundled minutes (definition only; RAT-01 consumes).
        Schema::create('voice_tariff_allowance', function (Blueprint $table) {
            $table->string('allowance_id')->primary();            // vta_...
            $table->string('operator_code');
            $table->string('tariff_plan_id');                     // FK voice_tariff_plan
            $table->string('allowance_code');                     // ONNET_100_MIN
            $table->string('allowance_name');
            $table->integer('included_seconds');                  // 6000
            $table->json('eligible_zone_codes_json');             // ["WIK_ON_NET"] (R-VOICE-TAR-10)
            $table->string('cycle_policy');                       // MONTHLY | BILL_CYCLE | TOPUP_VALIDITY
            $table->string('carry_over_policy')->default('NONE'); // NONE | ONE_CYCLE | CONFIGURED
            $table->integer('priority')->default(0);
            $table->string('status')->default('ACTIVE');          // ACTIVE | RETIRED
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'tariff_plan_id', 'allowance_code'], 'vta_op_plan_code_uniq');
        });

        // 7.7 voice_tariff_binding — binds a plan to a package, service, or subscription override.
        Schema::create('voice_tariff_binding', function (Blueprint $table) {
            $table->string('binding_id')->primary();              // vbn_...
            $table->string('operator_code');
            $table->string('binding_scope');                      // PACKAGE | SERVICE | SUBSCRIPTION_OVERRIDE
            $table->string('binding_ref');                        // package id / service id / subscription id
            $table->string('tariff_plan_id');                     // FK voice_tariff_plan
            $table->integer('priority')->default(0);              // higher wins if multiple apply
            $table->string('status')->default('ACTIVE');          // ACTIVE | RETIRED
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'binding_scope', 'binding_ref', 'status'], 'vbn_scope_ref_status_idx');
            $table->index(['tariff_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_tariff_binding');
        Schema::dropIfExists('voice_tariff_allowance');
        Schema::dropIfExists('voice_tariff_rate');
        Schema::dropIfExists('voice_time_band');
        Schema::dropIfExists('voice_destination_prefix');
        Schema::dropIfExists('voice_destination_zone');
        Schema::dropIfExists('voice_tariff_plan');
    }
};
