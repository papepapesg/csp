<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-05 wallet schema — owned by the BillingWallet module (extracted from the shared
 * billing-tables migration so the module is self-contained). No cross-aggregate FK; only
 * wallet_transaction → wallet (intra-module).
 */
return new class extends Migration
{
    public function up(): void
    {
        // BIL-05 — Wallet (PREPAID subscriptions).
        Schema::create('wallet', function (Blueprint $table) {
            $table->string('wallet_id')->primary();            // wlt_...
            $table->string('subscription_id')->unique();
            $table->string('account_id')->nullable()->index();
            $table->string('operator_code')->index();
            // No currency column: money wallets transact in the deployment currency
            // (operator_config.currency_code); allowance wallets have no currency at all.
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
    }
};
