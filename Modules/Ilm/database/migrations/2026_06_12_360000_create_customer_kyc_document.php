<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 §5.3 — KYC documents are first-class rows (not jsonb on the customer).
 * The bytes live in FOUNDATION_FILE_STORAGE; this table holds the reference (file_id +
 * storage_path), the authoritative mime/size, and the SHA-256 content_hash (R-ILM-K-6:
 * integrity verification + duplicate-document detection). Supersession (R-ILM-K-8) keeps
 * the original row + audit trail; documents are never hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_kyc_document', function (Blueprint $table) {
            $table->string('document_id')->primary();           // kycdoc_...
            $table->string('customer_id')->index();
            $table->string('operator_code')->index();
            $table->string('document_type');                    // NATIONAL_ID_FRONT | PASSPORT | BUSINESS_REG | ...
            $table->string('document_name');
            $table->string('file_id');                          // FOUNDATION_FILE_STORAGE reference
            $table->string('storage_path');                     // foundation path (denormalized for retrieval)
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('content_hash')->nullable();         // SHA-256 (R-ILM-K-6)
            $table->string('captured_by')->nullable();
            $table->string('verification_method')->default('VISUAL'); // VISUAL | IPRS_OCR_FUTURE | MANUAL_REVIEW
            $table->string('retention_class')->default('P10Y');
            $table->string('superseded_by_id')->nullable();     // R-ILM-K-8 never hard-delete
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'document_type']);
            $table->index('content_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_kyc_document');
    }
};
