<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOUNDATION_DROOLS (data-driven). A decision table is configurable business
 * POLICY stored as DATA — side-effect free, evaluated at runtime. Operators
 * override policy by deploying an operator-scoped table with the same rule_set,
 * with NO code change (the Drools-as-config equivalent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('decision_table', function (Blueprint $table) {
            $table->string('table_id')->primary();             // dt_...
            $table->string('rule_set')->index();               // e.g. subscription.activation-eligibility
            $table->unsignedInteger('version')->default(1);
            $table->string('operator_code')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('hit_policy')->default('FIRST');    // FIRST | COLLECT
            $table->json('inputs')->nullable();                // declared input fact names (for the editor)
            $table->json('rules');                             // [{ when:[{var,op,value}], then:{...} }]
            $table->json('default_output')->nullable();        // when no rule matches
            $table->string('status')->default('DEPLOYED')->index(); // DRAFT|DEPLOYED|RETIRED
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['rule_set', 'version', 'operator_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_table');
    }
};
