<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple PLM config catalogs: wallet_type (PLM-CFG-03), adjustment_type
 * (PLM-CFG-05/08), voice_tariff (PLM-CFG-07), equipment_type (PLM-CFG-06). Each is
 * an operator-scoped reference table consumed by billing / OSR at runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_type', function (Blueprint $table) {
            $table->string('wallet_type_id')->primary();        // wtyp_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('currency', 3)->default('KES');
            $table->boolean('allow_negative')->default(false);
            $table->boolean('auto_debit')->default(true);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        Schema::create('adjustment_type', function (Blueprint $table) {
            $table->string('adjustment_type_id')->primary();    // atyp_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('direction');                        // CREDIT | DEBIT
            $table->boolean('requires_approval')->default(true);
            $table->string('gl_code')->nullable();
            $table->boolean('taxable')->default(false);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        Schema::create('voice_tariff', function (Blueprint $table) {
            $table->string('voice_tariff_id')->primary();       // vtar_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('destination');                      // ONNET | OFFNET | INTERNATIONAL
            $table->decimal('rate_per_min', 10, 4)->default(0);
            $table->decimal('setup_fee', 10, 4)->default(0);
            $table->unsignedInteger('min_charge_seconds')->default(0);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        Schema::create('equipment_type', function (Blueprint $table) {
            $table->string('equipment_type_id')->primary();     // etyp_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();             // ONT | MODEM | STB | ...
            $table->decimal('default_deposit', 12, 2)->default(0);
            $table->unsignedInteger('warranty_days')->default(90);
            $table->boolean('serialized')->default(true);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_type');
        Schema::dropIfExists('voice_tariff');
        Schema::dropIfExists('adjustment_type');
        Schema::dropIfExists('wallet_type');
    }
};
