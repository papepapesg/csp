<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAY-GW-01 Payment Gateway Integration. A dedicated integration module that
 * validates + dedupes inbound gateway callbacks (M-Pesa, Visa, bank) then calls
 * the owning module BIL to apply the payment (MVP baseline §4: gateway callback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_callback', function (Blueprint $table) {
            $table->string('callback_id')->primary();          // pgcb_...
            $table->string('operator_code')->index();
            $table->string('provider');                        // MPESA | VISA | BANK_TRANSFER
            $table->string('external_ref');                    // provider transaction id (idempotency key)
            $table->string('account_ref')->nullable();         // paybill account / payment_account_number
            $table->string('resolved_account_id')->nullable()->index();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->json('raw')->nullable();
            $table->string('status')->default('RECEIVED')->index(); // RECEIVED|PROCESSED|REJECTED|DUPLICATE
            $table->string('payment_id')->nullable();
            $table->string('reject_reason')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();

            $table->unique(['provider', 'external_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_callback');
    }
};
