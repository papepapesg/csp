<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free legal invoice numbering counter, per (operator, fiscal year, type)
 * (BIL-02 §legal_invoice_number). A locked counter row guarantees no gaps or
 * duplicates under concurrency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequence', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('type');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();

            $table->primary(['operator_code', 'type', 'fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequence');
    }
};
