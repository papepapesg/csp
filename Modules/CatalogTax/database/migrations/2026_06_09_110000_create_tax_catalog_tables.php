<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-02 Tax Configuration. A stateless tax-compute config catalog: tax_rule
 * (one taxable category's tax with a rate, base_method and order) and tax_group
 * (an ordered set of rules). Rules are never deleted — deactivated by setting
 * effective_until — so historical charges keep referencing their definition
 * (R-PLM-02-CT-1). Operator-scoped (R-PLM-02-CT-3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rule', function (Blueprint $table) {
            $table->string('tax_rule_id')->primary();           // txr_...
            $table->string('operator_code')->index();
            $table->string('code');                             // WIK_INTERNET_VAT
            $table->string('name');
            $table->string('taxable_category')->nullable();     // INTERNET | TV | VOICE | EQUIPMENT
            $table->decimal('rate', 7, 4);                      // 0.1600
            $table->string('base_method')->default('BASE');     // BASE | BASE_PLUS_PRIOR
            $table->unsignedInteger('order_within_group')->default(1);
            $table->string('rounding_mode')->default('HALF_UP');
            $table->unsignedTinyInteger('rounding_scale')->default(2);
            $table->string('regulator')->nullable();            // KRA | URA | TRA
            $table->string('regulator_tax_code')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });

        Schema::create('tax_group', function (Blueprint $table) {
            $table->string('tax_group_id')->primary();          // txg_...
            $table->string('operator_code')->index();
            $table->string('code');                             // WIK_INTERNET
            $table->string('name');
            $table->json('order_within_group');                 // [tax_rule code, ...]
            $table->string('regulator_reference')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_group');
        Schema::dropIfExists('tax_rule');
    }
};
