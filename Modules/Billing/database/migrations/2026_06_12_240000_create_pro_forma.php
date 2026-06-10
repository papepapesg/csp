<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-GEN-01 Generator 3: pro-forma documents for PREPAID subscriptions a few
 * days before cycle close, so customers see what will be settled at the
 * boundary. Informational only (no legal number, no receivable); superseded when
 * the next pro forma is generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pro_forma', function (Blueprint $table) {
            $table->string('pro_forma_id')->primary();          // pf_...
            $table->string('operator_code')->index();
            $table->string('subscription_id')->index();
            $table->string('customer_id')->nullable();
            $table->string('currency', 3)->default('KES');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->json('lines')->nullable();                   // projected charge lines
            $table->json('customer_snapshot')->nullable();
            $table->timestamp('cycle_end')->nullable();
            $table->string('idempotency_cycle_key')->index();    // cycle_{YYYY}_{MM}_sub_{id}
            $table->string('status')->default('ACTIVE');         // ACTIVE | SUPERSEDED
            $table->string('superseded_by')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'idempotency_cycle_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pro_forma');
    }
};
