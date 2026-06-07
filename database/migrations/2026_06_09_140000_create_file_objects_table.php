<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOUNDATION_FILE_STORAGE — the platform file registry. Every uploaded artifact
 * (KYC document, field-audit photo, signed contract) is a row here pointing at a
 * configured Laravel disk, with owner linkage, checksum and metadata so any module
 * can attach files without owning storage mechanics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_object', function (Blueprint $table) {
            $table->string('file_id')->primary();               // file_...
            $table->string('operator_code')->index();
            $table->string('owner_type')->nullable()->index();  // Customer | WorkOrder | FieldAudit | ...
            $table->string('owner_id')->nullable()->index();
            $table->string('category')->nullable();             // KYC_ID | AUDIT_PHOTO | CONTRACT | ...
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('checksum')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_object');
    }
};
