<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLM-CFG-03 restructure — the wallet stack becomes wallet_type → wallet →
 * wallet_transaction (type, instance, ledger):
 *
 *  - CURRENCY IS DEPLOYMENT CONFIG. One operator deployment transacts in one
 *    currency (operator_config.currency_code); a catalog row must not be able
 *    to introduce a second one (no XOF wallets on a shilling deployment).
 *    Wallet instances are stamped with the operator currency at creation.
 *  - ONE catalog level. The old wallet_type (unit taxonomy) + wallet_catalog
 *    (behaviour) pair collapses into a single wallet_type table: `unit`
 *    (currency | points) moves onto the behaviour row; the taxonomy table and
 *    the wallet_type_code indirection are dropped.
 *  - CURRENCY-NEUTRAL CODES. MONEY_KES → MONEY etc.; a multi-country rollout
 *    reuses the same catalog. The suffix strip also runs over wallet.wallet_code
 *    and the PLM default_wallet_ref columns so references stay aligned.
 */
return new class extends Migration
{
    public function up(): void
    {
        // unit (currency | points) moves from the old wallet_type taxonomy onto the behaviour row.
        Schema::table('wallet_catalog', function (Blueprint $table) {
            $table->string('unit')->default('currency')->after('description'); // currency | points
        });
        DB::statement(
            "update wallet_catalog wc set unit = coalesce((select wt.unit from wallet_type wt
              where wt.operator_code = wc.operator_code and wt.code = wc.wallet_type_code), 'currency')"
        );

        Schema::table('wallet_catalog', function (Blueprint $table) {
            $table->dropColumn(['currency', 'wallet_type_code']);
        });

        // Currency-neutral codes: strip the ISO-4217 suffix everywhere it is referenced.
        DB::statement("update wallet_catalog set code = regexp_replace(code, '_[A-Z]{3}$', '')");
        DB::statement("update wallet set wallet_code = regexp_replace(wallet_code, '_[A-Z]{3}$', '')");
        foreach (['service_class', 'service', 'package'] as $table) {
            DB::statement("update {$table} set default_wallet_ref = regexp_replace(default_wallet_ref, '_[A-Z]{3}$', '') where default_wallet_ref is not null");
        }

        // The taxonomy table is gone; the behaviour table takes its rightful name.
        Schema::dropIfExists('wallet_type');
        Schema::rename('wallet_catalog', 'wallet_type');
        DB::statement('alter table wallet_type rename column wallet_catalog_id to wallet_type_id');
    }

    public function down(): void
    {
        DB::statement('alter table wallet_type rename column wallet_type_id to wallet_catalog_id');
        Schema::rename('wallet_type', 'wallet_catalog');
        Schema::table('wallet_catalog', function (Blueprint $table) {
            $table->string('currency', 3)->default('KES');
            $table->string('wallet_type_code')->nullable();
            $table->dropColumn('unit');
        });
        // The old taxonomy table is not restored (reference data, reseeded).
    }
};
