<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customer's tax identifier (VAT/PIN/NIF) — read into the tax-invoice customer snapshot by
 * BIL-02-TAX-01 and submitted to the tax authority at signing time. Nullable: many individuals
 * have no registered tax ID, and the signer applies jurisdiction-specific placeholder rules
 * (R-TAX-01-T-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer', function (Blueprint $table) {
            $table->string('tax_identifier')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('customer', fn (Blueprint $table) => $table->dropColumn('tax_identifier'));
    }
};
