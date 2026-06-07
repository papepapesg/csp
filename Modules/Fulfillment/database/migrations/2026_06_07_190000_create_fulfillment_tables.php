<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUL-02 Order Capture / onboarding orchestration. FUL owns order capture and
 * service-execution state; it coordinates readiness then hands ownership back to
 * ILM (customer), SUB-LM (subscription), BIL (payment), WO (field), OSR
 * (equipment). Onboarding is a workflow that ends (HLD §6.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_order', function (Blueprint $table) {
            $table->string('order_id')->primary();             // ford_...
            $table->string('operator_code')->index();
            $table->string('customer_id')->index();
            $table->string('account_id')->index();
            $table->string('homepass_id')->nullable();
            $table->string('package_ref');
            $table->string('package_version_id')->nullable();
            $table->string('billing_mode')->default('POSTPAID');
            $table->string('status')->default('CAPTURED')->index(); // CAPTURED|AWAITING_INSTALL|ACTIVATING|COMPLETED|CANCELLED
            $table->string('current_step')->nullable();
            $table->string('subscription_id')->nullable()->index();
            $table->string('work_order_id')->nullable()->index();
            $table->string('payment_ref')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('fulfillment_order_step', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('order_id')->index();
            $table->string('step');                            // CAPTURE|VALIDATE|KYC|PAYMENT|INSTALL|SUBSCRIPTION|ACTIVATION
            $table->string('status')->default('PENDING');      // PENDING|DONE|SKIPPED|FAILED
            $table->json('result')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('order_id')->on('fulfillment_order')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fulfillment_order_step');
        Schema::dropIfExists('fulfillment_order');
    }
};
