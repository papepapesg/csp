<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-03 CVM (Customer Value Management) — retention / recovery / win-back. A
 * cvm_activity is an offer made to an at-risk or churned customer, with the offer
 * resolved by rules.cvm.offer for the trigger + segment. Lifecycle: OFFERED ->
 * ACCEPTED / DECLINED / EXPIRED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cvm_activity', function (Blueprint $table) {
            $table->string('activity_id')->primary();           // cvm_...
            $table->string('operator_code')->index();
            $table->string('customer_id')->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('type');                             // RETENTION | RECOVERY | WINBACK
            $table->string('trigger_reason')->nullable();       // NON_PAYMENT | CHURN_RISK | DOWNGRADE | ...
            $table->string('offer_code')->nullable();
            $table->json('offer_details')->nullable();
            $table->string('status')->default('OFFERED')->index(); // OFFERED|ACCEPTED|DECLINED|EXPIRED
            $table->string('channel')->nullable();
            $table->string('assigned_to')->nullable();
            $table->string('outcome_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cvm_activity');
    }
};
