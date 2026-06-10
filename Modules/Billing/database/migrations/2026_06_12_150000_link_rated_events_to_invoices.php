<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAT-01 §8.2/§9.2: rated usage is "the source of truth for usage charge audit
 * and postpaid invoice generation" — when invoice generation consumes it, it is
 * marked invoiced WITH the invoice id (mark-invoiced "must include the invoice
 * id and the list of rated usage ids"). This link is what makes the invoice's
 * itemized usage pages possible: from a voice summary line back to every rated
 * call (destination, time, duration) that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rated_event', function (Blueprint $table) {
            $table->string('invoice_id')->nullable()->index()->after('billed'); // settlement_ref for POSTPAID (status INVOICED)
        });
    }

    public function down(): void
    {
        Schema::table('rated_event', function (Blueprint $table) {
            $table->dropColumn('invoice_id');
        });
    }
};
