<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-03 `wallet` catalog — the deployment's catalog of wallets (NOT the
 * per-customer balance; that ledger is BIL-06, kept in `wallet`). Each entry is
 * referenced by Service defs (`default_wallet_ref`), Package/Discount defs via its
 * `code` (the cross-module `walletRef`). The catalog declares what wallets exist
 * and how they behave: which billing modes may use them (`applicability`), the
 * order they are charged in (`charging_precedence`), expiry, refill, and the
 * points→currency conversion. Lifecycle DRAFT → ACTIVE → RETIRED (R-W-14).
 *
 * Named `wallet_catalog` to avoid colliding with the BIL-06 ledger table `wallet`;
 * it is the PLM-CFG-03 `wallet` entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PLM-CFG-03 wallet_type carries the value `unit` (currency | points) that
        // R-W-15 keys on; the original simplified catalog omitted it.
        Schema::table('wallet_type', function (Blueprint $table) {
            $table->string('unit')->default('currency')->after('name'); // currency | points
        });

        Schema::create('wallet_catalog', function (Blueprint $table) {
            $table->string('wallet_catalog_id')->primary();           // wcat_...
            $table->string('operator_code')->index();
            $table->string('code');                                   // walletRef, e.g. MONEY_KES (R-W-1)
            $table->string('description');
            $table->string('wallet_type_code');                       // FK to wallet_type.code (R-W-3)
            $table->string('currency', 3);                            // ISO 4217 (R-W-4); one wallet = one currency
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
        Schema::dropIfExists('wallet_catalog');
        Schema::table('wallet_type', fn (Blueprint $t) => $t->dropColumn('unit'));
    }
};
