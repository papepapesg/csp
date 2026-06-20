<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 R-ILM-F-3/4/5: an account flag's catalog row declares its cross-module effects —
 * affects_dunning (BIL-04 may escalate faster), affects_provisioning (FUL-03 blocks activation,
 * e.g. FRAUD_SUSPECTED), and customer_visible (surfaced in customer-facing UIs vs BO-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_account_flag_catalog', function (Blueprint $table) {
            $table->boolean('affects_dunning')->default(false)->after('surfaces_attention');
            $table->boolean('affects_provisioning')->default(false)->after('affects_dunning');
            $table->boolean('customer_visible')->default(false)->after('affects_provisioning');
        });
    }

    public function down(): void
    {
        Schema::table('customer_account_flag_catalog', fn (Blueprint $table) => $table->dropColumn(['affects_dunning', 'affects_provisioning', 'customer_visible']));
    }
};
