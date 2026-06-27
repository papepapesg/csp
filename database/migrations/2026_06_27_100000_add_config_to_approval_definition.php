<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-04 — a per-process config bag on the approval definition (the "process" row). The
 * approval engine stays domain-agnostic; each owning domain stores the config that governs
 * its process here (e.g. adjustment limits), instead of a separate per-domain config table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_definition', function (Blueprint $table) {
            $table->json('config')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('approval_definition', function (Blueprint $table) {
            $table->dropColumn('config');
        });
    }
};
