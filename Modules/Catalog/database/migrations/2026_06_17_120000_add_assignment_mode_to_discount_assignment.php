<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIP-03 discount assignment — make the assignment MODE explicit (Option B) instead of
 * inferring it from a nullable campaign_id. A DIRECT assignment applies on its own
 * validity; a CAMPAIGN assignment is additionally gated by its campaign's window/status.
 * This removes the back-office trap of "active but not applying" — the mode is a column,
 * and the runtime gates CAMPAIGN ones on the live campaign. Existing rows are backfilled:
 * a campaign_id present => CAMPAIGN, otherwise DIRECT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_assignment', function (Blueprint $table) {
            $table->string('assignment_mode')->default('DIRECT')->after('campaign_id'); // DIRECT | CAMPAIGN
            $table->index(['operator_code', 'assignment_mode', 'status'], 'da_mode_lookup');
        });

        DB::statement("UPDATE discount_assignment SET assignment_mode = 'CAMPAIGN' WHERE campaign_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('discount_assignment', function (Blueprint $table) {
            $table->dropIndex('da_mode_lookup');
            $table->dropColumn('assignment_mode');
        });
    }
};
