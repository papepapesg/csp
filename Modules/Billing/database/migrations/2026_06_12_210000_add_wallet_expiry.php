<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-05 / PLM-CFG-03 R-W-9: an expiring wallet's balance is valid for
 * expiry_period_days after the last top-up. expires_at carries that deadline;
 * the expiry sweep zeroes the balance past it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->index()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('wallet', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
