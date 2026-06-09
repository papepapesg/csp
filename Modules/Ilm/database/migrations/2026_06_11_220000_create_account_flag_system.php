<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 §3.5 account flag system + sub-status catalog (both operator-extensible
 * config). customer_account_flag_catalog defines the flag types an operator runs (NPD,
 * churn risk, fraud, …); customer_account_flag holds each account's current flag state.
 * customer_sub_status_catalog defines the per-operator sub-status registry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_account_flag_catalog', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('flag_code');                        // NPD, CHURN_RISK, FRAUD_SUSPECTED, ...
            $table->string('name');
            $table->string('value_kind')->default('BOOLEAN');   // BOOLEAN | SCORE_0_100 | TIER | COUNT
            $table->string('evaluator')->default('MANUAL');     // MANUAL | DROOLS | EVENT_DRIVEN
            $table->boolean('surfaces_attention')->default(false); // drives the attention banner
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'flag_code']);
        });

        Schema::create('customer_account_flag', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code')->index();
            $table->string('account_id')->index();
            $table->string('flag_code');
            $table->boolean('bool_value')->nullable();
            $table->unsignedInteger('score_value')->nullable();
            $table->string('text_value')->nullable();
            $table->string('state')->default('ACTIVE');         // ACTIVE | CLEARED
            $table->string('source')->nullable();               // MANUAL | DROOLS | <event>
            $table->string('set_by')->nullable();
            $table->timestamp('set_at')->useCurrent();
            $table->timestamps();

            $table->unique(['account_id', 'flag_code']);
        });

        Schema::create('customer_sub_status_catalog', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('sub_status_code');                  // active, seasonal_disconnect, ...
            $table->string('main_status');                      // ACTIVE | INACTIVE
            $table->string('display_name');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'sub_status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_sub_status_catalog');
        Schema::dropIfExists('customer_account_flag');
        Schema::dropIfExists('customer_account_flag_catalog');
    }
};
