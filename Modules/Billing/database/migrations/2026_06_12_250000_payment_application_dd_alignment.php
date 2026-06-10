<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-01-PAY-01 alignment: idempotency key (RC-3), reversal lineage (RV-2),
 * allocation audit fields (AL-5), and a per-operator payment config (allocation
 * + overpayment policy, AL-2 / OV-1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->string('customer_id')->nullable()->after('account_id');
            $table->string('payment_reference')->nullable()->after('gateway_ref'); // idempotency key (RC-3)
            $table->string('reversal_of_payment_id')->nullable()->after('status');  // RV-2
            $table->string('reversal_reason_code')->nullable()->after('reversal_of_payment_id');
            $table->string('reversed_by')->nullable()->after('reversal_reason_code');
            $table->unique(['account_id', 'payment_reference']);
        });

        Schema::table('payment_invoice_allocation', function (Blueprint $table) {
            $table->decimal('outstanding_before', 14, 2)->nullable()->after('allocated_amount');
            $table->decimal('outstanding_after', 14, 2)->nullable()->after('outstanding_before');
            $table->string('allocation_strategy')->nullable()->after('outstanding_after');
        });

        // pay_01_config: allocation + overpayment policy per operator (AL-2 / OV-1).
        Schema::create('payment_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->string('allocation_policy')->default('FIFO_DUE_DATE');   // FIFO_DUE_DATE | FIFO_ISSUE_DATE | LARGEST_FIRST | SMALLEST_FIRST
            $table->string('overpayment_policy')->default('APPLY_TO_NEXT_OPEN'); // APPLY_TO_NEXT_OPEN | WALLET_TOPUP | MANUAL_REVIEW
            $table->string('overpayment_overflow_wallet_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_config');
        Schema::table('payment_invoice_allocation', function (Blueprint $table) {
            $table->dropColumn(['outstanding_before', 'outstanding_after', 'allocation_strategy']);
        });
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'payment_reference']);
            $table->dropColumn(['customer_id', 'payment_reference', 'reversal_of_payment_id', 'reversal_reason_code', 'reversed_by']);
        });
    }
};
