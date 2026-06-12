<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-02 R-PLM-02-CT-1/R-VOICE style versioning for tax: a rule code is versioned
 * by effective window, never deleted. The original (operator_code, code) unique blocked
 * a second version of the same code, so we widen it to (operator_code, code, effective_from).
 * The compute path already selects the row active at taxable_at; the admin service closes
 * the prior version's effective_until so windows never overlap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rule', function (Blueprint $table) {
            $table->dropUnique('tax_rule_operator_code_code_unique');
            $table->unique(['operator_code', 'code', 'effective_from'], 'tax_rule_operator_code_version_unique');
            $table->index(['operator_code', 'code', 'effective_from'], 'tax_rule_code_effective_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tax_rule', function (Blueprint $table) {
            $table->dropUnique('tax_rule_operator_code_version_unique');
            $table->dropIndex('tax_rule_code_effective_idx');
            $table->unique(['operator_code', 'code'], 'tax_rule_operator_code_code_unique');
        });
    }
};
