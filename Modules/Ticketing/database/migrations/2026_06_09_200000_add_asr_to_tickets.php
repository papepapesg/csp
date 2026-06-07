<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ASR-01..04 specialisation on TCK-01 tickets. asr_type tags the case as Technical
 * Trouble (ASR-01) / Information Request (ASR-02) / Complaint (ASR-03) / Service
 * Request (ASR-04); rules.asr.routing decides the queue + auto-actions per type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket', function (Blueprint $table) {
            $table->string('asr_type')->nullable()->index()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('ticket', function (Blueprint $table) {
            $table->dropColumn('asr_type');
        });
    }
};
