<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing core: invoicing (BIL-02), payment application (BIL-01-PAY-01) and
 * wallet/top-up (BIL-05). BIL owns money; account_id is the billing/collection
 * partition (HLD §5, BIL-02 R-BIL-02-E-1a). Tax/dunning are Wave-2 additions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // BIL-02 — Invoice header.
        Schema::create('invoice', function (Blueprint $table) {
            $table->string('invoice_id')->primary();           // inv_...
            $table->string('legal_invoice_number')->nullable()->unique(); // gap-free per operator/year/type
            $table->string('account_id')->index();             // billing partition
            $table->string('customer_id')->nullable()->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('operator_code')->index();
            $table->string('type')->default('STANDARD');       // STANDARD | TAX | CREDIT_NOTE | DEBIT_NOTE
            $table->string('currency', 3)->default('KES');
            $table->string('billing_mode')->default('POSTPAID');
            $table->string('status')->default('OPEN')->index(); // OPEN|PARTIALLY_PAID|PAID|VOID|OVERDUE
            $table->string('original_invoice_id')->nullable(); // credit/debit notes
            $table->timestamp('issue_date')->useCurrent();
            $table->timestamp('due_date')->nullable();
            $table->decimal('subtotal_amount', 14, 2)->default(0);
            $table->decimal('tax_amount_total', 14, 2)->default(0);
            $table->json('tax_summary')->nullable();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->timestamps();
        });

        // BIL-02 — Invoice line.
        Schema::create('invoice_line', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('invoice_id')->index();
            $table->string('description');
            $table->string('service_ref')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->timestamps();

            $table->foreign('invoice_id')->references('invoice_id')->on('invoice')->cascadeOnDelete();
        });

        // BIL-01-PAY-01 — Payment ledger (inbound money).
        Schema::create('payment_ledger', function (Blueprint $table) {
            $table->string('payment_id')->primary();           // pay_...
            $table->string('account_id')->index();
            $table->string('operator_code')->index();
            $table->string('method');                          // MPESA | VISA | BANK_TRANSFER | OFFLINE
            $table->string('gateway_ref')->nullable();
            $table->string('currency', 3)->default('KES');
            $table->decimal('paid_amount', 14, 2);
            $table->decimal('unallocated_amount', 14, 2)->default(0);
            $table->string('status')->default('RECEIVED');     // RECEIVED|APPLIED|PARTIALLY_APPLIED|REVERSED
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        // BIL-01-PAY-01 — Payment -> invoice allocation.
        Schema::create('payment_invoice_allocation', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('payment_id')->index();
            $table->string('invoice_id')->index();
            $table->decimal('allocated_amount', 14, 2);
            $table->timestamps();

            $table->foreign('payment_id')->references('payment_id')->on('payment_ledger')->cascadeOnDelete();
        });

        // BIL-01-PAY-01 — Per-account surplus credit balance.
        Schema::create('account_credit_balance', function (Blueprint $table) {
            $table->string('account_id')->primary();
            $table->string('operator_code')->index();
            $table->string('currency', 3)->default('KES');
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamps();
        });

        // BIL-05 — Wallet (PREPAID subscriptions).
        Schema::create('wallet', function (Blueprint $table) {
            $table->string('wallet_id')->primary();            // wlt_...
            $table->string('subscription_id')->unique();
            $table->string('account_id')->nullable()->index();
            $table->string('operator_code')->index();
            $table->string('currency', 3)->default('KES');
            $table->decimal('balance', 14, 2)->default(0);
            $table->string('status')->default('ACTIVE');       // ACTIVE | FROZEN | CLOSED
            $table->timestamps();
        });

        // BIL-05 — Wallet transaction ledger.
        Schema::create('wallet_transaction', function (Blueprint $table) {
            $table->string('id')->primary();                   // wtx_...
            $table->string('wallet_id')->index();
            $table->string('direction');                       // CREDIT | DEBIT
            $table->string('reason');                          // TOPUP | CYCLE_CHARGE | REFUND | BONUS | CORRECTION | RECOVERY
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);
            $table->string('reference')->nullable();
            $table->timestamps();

            $table->foreign('wallet_id')->references('wallet_id')->on('wallet')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transaction');
        Schema::dropIfExists('wallet');
        Schema::dropIfExists('account_credit_balance');
        Schema::dropIfExists('payment_invoice_allocation');
        Schema::dropIfExists('payment_ledger');
        Schema::dropIfExists('invoice_line');
        Schema::dropIfExists('invoice');
    }
};
