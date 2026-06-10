<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-01 hardening before the stock-chain tour:
 *  - R-OSR-SC-9: write-off / cycle-count-adjustment movements (reason codes with
 *    requires_approval=true) must record a second-person approver → stock_movement.approved_by.
 *  - R-OSR-SC-7: a reservation auto-expires (default created_at + 30 days) and is swept
 *    back to available → stock_reservation.expires_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movement', function (Blueprint $table) {
            $table->string('approved_by')->nullable()->after('reference'); // second-person approver for requires_approval reasons
        });

        Schema::table('stock_reservation', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('status'); // auto-release deadline (R-OSR-SC-7)
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservation', fn (Blueprint $t) => $t->dropColumn('expires_at'));
        Schema::table('stock_movement', fn (Blueprint $t) => $t->dropColumn('approved_by'));
    }
};
