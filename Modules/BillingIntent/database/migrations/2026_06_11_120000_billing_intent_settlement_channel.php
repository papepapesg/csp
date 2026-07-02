<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-01: a billing intent records HOW it was settled. Postpaid fees raise an
 * invoice (INVOICE); prepaid fees are charged against the customer's prepaid
 * wallet balance (WALLET, PLM-CFG-03); credits post account credit (CREDIT);
 * zero-amount intents settle with nothing (NONE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_intent', function (Blueprint $table) {
            $table->string('settlement_channel')->default('NONE')->after('status'); // INVOICE | WALLET | CREDIT | NONE
        });
    }

    public function down(): void
    {
        Schema::table('billing_intent', fn (Blueprint $t) => $t->dropColumn('settlement_channel'));
    }
};
