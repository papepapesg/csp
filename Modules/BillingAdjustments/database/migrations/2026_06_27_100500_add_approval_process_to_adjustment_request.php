<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which pre-authored ADJUSTMENT approval process (the approval_definition action,
 * e.g. SINGLE / DUAL) the rules engine selected for this proposal — so a re-opened gate
 * (after override/revision) raises against the same process.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->string('approval_process')->nullable()->after('approval_rule_id');
        });
    }

    public function down(): void
    {
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->dropColumn('approval_process');
        });
    }
};
