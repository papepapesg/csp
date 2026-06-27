<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-TAX-01 Tax Invoice & Gateway. A tax invoice is an invoice fiscalised by
 * the tax authority gateway (e.g. KRA), carrying a fiscal/control number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_invoice', function (Blueprint $table) {
            $table->string('tax_invoice_id')->primary();       // tinv_...
            $table->string('invoice_id')->index();
            $table->string('operator_code')->index();
            $table->string('fiscal_number')->nullable()->unique();
            $table->string('control_code')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->string('status')->default('PENDING');      // PENDING|FISCALISED|FAILED
            $table->json('response')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice');
    }
};
