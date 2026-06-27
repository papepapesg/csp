<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-01 / BIL-CFG-01 billable-event "intent" raised by a subscription operation
 * during its commit window (bil01-emit-intent). Records the charge the operation
 * implies — upgrade/downgrade proration delta, pause / reconnection fee, deposit
 * refund — links it to the operation, optionally creates the fee invoice, and
 * tracks payment for pay-first gating (AWAITING_PAYMENT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_intent', function (Blueprint $table) {
            $table->string('intent_id')->primary();             // bint_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->index();
            $table->string('account_id')->nullable()->index();
            $table->string('operation_id')->nullable()->index();
            $table->string('intent_type');                      // PRORATION | PAUSE_FEE | RECONNECTION_FEE | DEPOSIT_REFUND
            $table->decimal('amount', 14, 2)->default(0);       // signed: +charge / -credit
            $table->string('currency', 3)->default('KES');
            $table->boolean('pay_first')->default(false);
            $table->string('status')->default('PENDING')->index(); // PENDING | CHARGED | CONFIRMED | WAIVED | REFUNDED
            $table->string('invoice_id')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_intent');
    }
};
