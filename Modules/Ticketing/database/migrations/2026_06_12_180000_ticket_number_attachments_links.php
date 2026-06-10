<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01: human-facing gap-free ticket_number (UNIQUE per operator), ticket
 * attachments metadata, and general multi-entity ticket links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket', function (Blueprint $table) {
            $table->string('ticket_number')->nullable()->after('ticket_id'); // human-facing reference
            $table->unique(['operator_code', 'ticket_number']);
        });

        // Gap-free per-operator ticket number counter.
        Schema::create('ticket_number_sequence', function (Blueprint $table) {
            $table->string('operator_code');
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestamps();
            $table->primary(['operator_code', 'fiscal_year']);
        });

        // Attachment metadata (the file itself lives in FOUNDATION_FILE_STORAGE).
        Schema::create('ticket_attachment', function (Blueprint $table) {
            $table->string('attachment_id')->primary();          // tatt_...
            $table->string('ticket_id')->index();
            $table->string('file_id');                           // foundation file ref
            $table->string('file_name');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('uploaded_by')->nullable();
            $table->timestamps();
        });

        // General links to other entities (subscription, invoice, WO, another ticket…).
        Schema::create('ticket_link', function (Blueprint $table) {
            $table->string('link_id')->primary();                // tlnk_...
            $table->string('ticket_id')->index();
            $table->string('entity_type');                       // SUBSCRIPTION | INVOICE | WORK_ORDER | TICKET | CUSTOMER | …
            $table->string('entity_ref');
            $table->string('relation')->default('RELATED');      // RELATED | DUPLICATE_OF | CAUSED_BY | …
            $table->string('linked_by')->nullable();
            $table->timestamps();
            $table->unique(['ticket_id', 'entity_type', 'entity_ref', 'relation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_link');
        Schema::dropIfExists('ticket_attachment');
        Schema::dropIfExists('ticket_number_sequence');
        Schema::table('ticket', function (Blueprint $table) {
            $table->dropUnique(['operator_code', 'ticket_number']);
            $table->dropColumn('ticket_number');
        });
    }
};
