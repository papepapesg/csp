<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-TAX-01 full model. A tax invoice is the legal document proving tax was declared on a
 * received customer payment, signed asynchronously by the operator's tax-authority gateway.
 * Evolves tax_invoice into the DD's payment-triggered, signing-state-machine shape and adds:
 *   tax_invoice_signing_failure  one row per failed signing attempt (audit, F-5)
 *   tax_operator_config          per-operator enablement + signer ref + timeout (T-4/S-1/S-7)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoice', function (Blueprint $table) {
            $table->string('subscription_id')->nullable()->after('invoice_id');
            $table->string('customer_id')->nullable()->after('subscription_id');
            $table->string('billing_mode')->nullable()->after('customer_id');           // POSTPAID | PREPAID
            $table->string('triggering_event_type')->nullable()->after('billing_mode');  // PAYMENT_APPLIED | WALLET_TOPPED_UP | PAYMENT_RECEIVED
            $table->string('triggering_event_ref')->nullable()->after('triggering_event_type'); // idempotency key (T-5)
            $table->string('original_invoice_id')->nullable()->after('triggering_event_ref');    // parent invoice (POSTPAID) or pro forma
            $table->string('legal_invoice_number')->nullable()->after('original_invoice_id');     // TAX_INVOICE sequence (G-8)
            $table->string('currency', 8)->nullable()->after('legal_invoice_number');
            $table->decimal('subtotal_amount', 15, 2)->default(0)->after('currency');     // pre-tax base
            $table->decimal('tax_total', 15, 2)->default(0)->after('subtotal_amount');
            $table->decimal('total_amount', 15, 2)->default(0)->after('tax_total');       // tax-inclusive paid amount
            $table->json('tax_summary')->nullable()->after('total_amount');               // invoice-level tax breakdown
            $table->json('line_items')->nullable()->after('tax_summary');
            $table->json('customer_snapshot')->nullable()->after('line_items');
            $table->string('payment_status')->default('PAID')->after('status');           // PAID by definition (G-9)
            $table->string('signed_invoice_number')->nullable()->after('payment_status');  // authority reference (S-3)
            $table->timestamp('signed_at')->nullable()->after('signed_invoice_number');
            $table->string('signing_failure_type')->nullable()->after('signed_at');        // GATEWAY_TIMEOUT | VALIDATION_FAILURE | AUTH_FAILURE | ...
            $table->integer('retry_count')->default(0)->after('signing_failure_type');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->json('metadata')->nullable()->after('next_retry_at');                   // tax_signature_data, topup_event_id, resolution notes
            $table->string('cancel_reason_code')->nullable()->after('metadata');
            $table->string('cancellation_reference')->nullable()->after('cancel_reason_code'); // authority cancellation ref (C-1)
            $table->string('cancel_requested_by')->nullable()->after('cancellation_reference'); // dual-approval requester
            $table->text('resolution_notes')->nullable()->after('cancel_requested_by');

            $table->unique(['operator_code', 'triggering_event_ref'], 'tax_invoice_idem');
            $table->index(['operator_code', 'status']);
            $table->index(['status', 'next_retry_at'], 'tax_invoice_retry_scan');
        });

        // The DD status set: GENERATED -> PENDING_SIGNATURE -> SIGNED | SIGNING_FAILED ->
        // GAVE_UP_AUTO; plus CANCELLED. The legacy default 'PENDING' is widened by the model.
        Schema::create('tax_invoice_signing_failure', function (Blueprint $table) {
            $table->string('id')->primary();                 // tisf_...
            $table->string('tax_invoice_id')->index();
            $table->string('operator_code')->index();
            $table->integer('attempt_number')->default(1);
            $table->timestamp('failed_at');
            $table->string('failure_type');                  // GATEWAY_TIMEOUT | GATEWAY_UNREACHABLE | VALIDATION_FAILURE | AUTH_FAILURE
            $table->string('failure_category')->nullable();  // TRANSIENT | VALIDATION
            $table->string('gateway_response_code')->nullable();
            $table->text('gateway_response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('retry_scheduled_at')->nullable();
            $table->timestamps();

            $table->index(['tax_invoice_id', 'attempt_number']);
        });

        Schema::create('tax_operator_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->boolean('enabled')->default(false);                          // T-4: generate tax invoices?
            $table->string('signing_service_implementation_ref')->default('stub'); // S-1: which signer
            $table->integer('signing_timeout_seconds')->default(30);             // S-7
            $table->string('legal_number_format')->nullable();                   // G-8 per-operator format
            $table->json('config')->nullable();                                  // signer-specific (cert refs, endpoint)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_operator_config');
        Schema::dropIfExists('tax_invoice_signing_failure');
        Schema::table('tax_invoice', function (Blueprint $table) {
            $table->dropUnique('tax_invoice_idem');
            $table->dropColumn([
                'subscription_id', 'customer_id', 'billing_mode', 'triggering_event_type', 'triggering_event_ref',
                'original_invoice_id', 'legal_invoice_number', 'currency', 'subtotal_amount', 'tax_total', 'total_amount',
                'tax_summary', 'line_items', 'customer_snapshot', 'payment_status', 'signed_invoice_number', 'signed_at',
                'signing_failure_type', 'retry_count', 'next_retry_at', 'metadata', 'cancel_reason_code',
                'cancellation_reference', 'cancel_requested_by', 'resolution_notes',
            ]);
        });
    }
};
