<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rendered-artifact registry — a first-class presentation of a domain entity (an
 * invoice PDF, an SMS body, …), produced by the document layer and reusable, independent
 * of any send. PDF/HTML artifacts reference a Foundation Files object; short text formats
 * keep their content inline. One entity may have many artifacts (one per format/locale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rendered_artifact', function (Blueprint $table) {
            $table->string('artifact_id')->primary();
            $table->string('operator_code');
            $table->string('entity_type');           // e.g. INVOICE
            $table->string('entity_id');
            $table->string('format');                // PDF | EMAIL_HTML | SMS_TEXT | …
            $table->string('locale')->default('en');
            $table->string('purpose_code');          // the template purpose used
            $table->string('template_id')->nullable();
            $table->string('file_id')->nullable();   // Foundation Files ref (PDF/HTML)
            $table->text('content')->nullable();     // inline body (short text formats)
            $table->string('content_hash', 64)->nullable();
            $table->string('status')->default('RENDERED');
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();

            // One current artifact per (entity, format, locale); re-render replaces it.
            $table->unique(['operator_code', 'entity_type', 'entity_id', 'format', 'locale'], 'rendered_artifact_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rendered_artifact');
    }
};
