<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-GEN-01 R-GEN-01-F-6: every generated invoice captures a
 * customer_snapshot at the moment of generation and never modifies it. The
 * snapshot freezes the customer's identity, language preference and billing
 * location so the document is immutable and historically faithful even if the
 * customer master later changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->json('customer_snapshot')->nullable()->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn('customer_snapshot');
        });
    }
};
