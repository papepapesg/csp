<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT-01 template studio catalogs. notification_template holds a per-operator,
 * per-CHANNEL, per-locale message template (subject + body with {{placeholders}});
 * the same template_code (e.g. SUBSCRIPTION_ACTIVATED) has independent SMS / EMAIL /
 * PUSH / WHATSAPP variants. invoice_template holds a designable invoice layout. Both
 * are authored in the studio (DRAFT → ACTIVE) and resolved at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_template', function (Blueprint $table) {
            $table->string('template_id')->primary();           // ntpl_...
            $table->string('operator_code')->index();
            $table->string('template_code');                    // SUBSCRIPTION_ACTIVATED, INVOICE_ISSUED, ...
            $table->string('channel');                          // SMS | EMAIL | PUSH | WHATSAPP
            $table->string('locale')->default('en');
            $table->string('subject')->nullable();              // EMAIL/PUSH title
            $table->text('body');                               // with {{variable}} placeholders
            $table->json('variables')->nullable();              // declared placeholders (for the studio)
            $table->string('status')->default('DRAFT')->index(); // DRAFT | ACTIVE | RETIRED
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'template_code', 'channel', 'locale']);
        });

        Schema::create('invoice_template', function (Blueprint $table) {
            $table->string('template_id')->primary();           // itpl_...
            $table->string('operator_code')->index();
            $table->string('code');                             // STANDARD, TAX, CREDIT_NOTE, ...
            $table->string('name');
            $table->json('layout');                             // designable sections/blocks (header, lines, totals, footer)
            $table->string('status')->default('DRAFT')->index();
            $table->string('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_template');
        Schema::dropIfExists('notification_template');
    }
};
