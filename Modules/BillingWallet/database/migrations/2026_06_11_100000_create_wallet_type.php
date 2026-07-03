<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-03 wallet_type — the deployment's catalog of wallets (NOT the
 * per-customer balance; that ledger is BIL-06, kept in `wallet`). Each entry is
 * referenced by Service / Package / Discount defs via its `code` (the
 * cross-module `walletRef`). The catalog declares what wallets exist and how
 * they behave: unit (currency | points), which billing modes may use them
 * (`applicability`), the order they are charged in (`charging_precedence`),
 * expiry, refill, and the points→currency conversion. Lifecycle DRAFT → ACTIVE
 * → RETIRED (R-W-14).
 *
 * Deliberately currency-free: a deployment transacts in ONE currency
 * (operator_config.currency_code), stamped onto the wallet instance at creation
 * — the catalog cannot introduce a second currency, and codes are
 * currency-neutral (MONEY, not MONEY_KES). A multi-country rollout reuses one
 * catalog with a different operator currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_type', function (Blueprint $table) {
            $table->string('wallet_type_id')->primary();              // wtyp_...
            $table->string('operator_code')->index();
            $table->string('code');                                   // walletRef, e.g. MONEY (R-W-1)
            $table->string('description');
            $table->string('unit')->default('currency');             // currency | points (R-W-15)
            $table->unsignedTinyInteger('decimal_precision')->default(2); // 0..4 (R-W-6)
            $table->string('applicability')->default('ANY');          // PREPAID_ONLY | POSTPAID_ONLY | ANY (R-W-7)
            $table->boolean('expires')->default(false);               // R-W-9
            $table->unsignedInteger('expiry_period_days')->nullable(); // required when expires (R-W-9)
            $table->unsignedInteger('charging_precedence')->default(100); // lower applied first (R-W-10)
            $table->boolean('refillable')->default(true);             // accepts top-ups (R-W-11)
            $table->decimal('points_to_currency_rate', 18, 6)->nullable(); // points wallets only (R-W-15)
            $table->string('status')->default('DRAFT');               // DRAFT | ACTIVE | RETIRED (R-W-14)
            $table->timestamp('retired_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);                // R-W-1
            // Hot read at charging time (BIL-01/BIL-06): active wallets by precedence.
            $table->index(['operator_code', 'status', 'charging_precedence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_type');
    }
};
