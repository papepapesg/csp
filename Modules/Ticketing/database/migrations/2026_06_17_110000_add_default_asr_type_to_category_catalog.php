<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01 §7.6: fold the ASR classification into the category catalog as a preset,
 * alongside default_priority/default_queue/wo_allowed. Picking a category now also
 * presets the ASR type (ASR-01..04); the ticket's own asr_type is seeded from this
 * default at creation but stays override-able per ticket (like default_priority).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_category_catalog', function (Blueprint $table) {
            // ASR-01 TECHNICAL_TROUBLE | ASR-02 INFORMATION_REQUEST | ASR-03 COMPLAINT | ASR-04 SERVICE_REQUEST
            $table->string('default_asr_type')->nullable()->after('default_queue');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_category_catalog', fn (Blueprint $t) => $t->dropColumn('default_asr_type'));
    }
};
