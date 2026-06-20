<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-01-SC (R-BIL-01-SC-1): an intent snapshots the matched BillableEvent's state_callback
 * so that, once the charge is settled, BIL-01 can drive the SUB-LM-01 transition it gates
 * (e.g. RECONNECTION_FEE_AFTER_DUNNING -> SUSPENDED_NP becomes ACTIVE). Pinning it on the
 * intent lets a pay-first callback fire on the LATER payment confirmation, not just inline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_intent', function (Blueprint $table) {
            $table->json('state_callback')->nullable()->after('settlement_channel'); // {transitionCode, targetStatus}
        });
    }

    public function down(): void
    {
        Schema::table('billing_intent', fn (Blueprint $table) => $table->dropColumn('state_callback'));
    }
};
