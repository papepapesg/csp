<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-06 ledger rework: a customer/subscription may hold MORE THAN ONE wallet —
 * one per PLM-CFG-03 catalog `walletRef` (e.g. MONEY_KES for Internet+TV settlement
 * and VOICE_KES for usage-based Phone). Each ledger row now references the catalog
 * entry by `wallet_code`; uniqueness moves from (subscription) to
 * (subscription, wallet_code) so the single-wallet-per-subscription limit is lifted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet', function (Blueprint $table) {
            $table->dropUnique(['subscription_id']);              // was 1:1 subscription
            $table->string('wallet_code')->nullable()->after('subscription_id'); // PLM-CFG-03 walletRef
            $table->string('customer_id')->nullable()->after('account_id');
            $table->unique(['subscription_id', 'wallet_code']);   // one ledger row per (subscription, walletRef)
            $table->index(['account_id', 'wallet_code']);         // per-account wallet lookup
        });
    }

    public function down(): void
    {
        Schema::table('wallet', function (Blueprint $table) {
            $table->dropUnique(['subscription_id', 'wallet_code']);
            $table->dropIndex(['account_id', 'wallet_code']);
            $table->dropColumn(['wallet_code', 'customer_id']);
            $table->unique('subscription_id');
        });
    }
};
