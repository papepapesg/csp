<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROV-INT-01 R-PROV-07: a destructive force-sync's approval is owned by the EM-CFG-04 engine
 * (config-driven gating, segregation of duties, immutable decision audit) rather than a bare
 * approved_by flip. Store the approval reference so approve()/execute() can honour it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_force_sync_request', function (Blueprint $table) {
            $table->string('approval_request_id')->nullable()->after('status'); // EM-CFG-04 request
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_force_sync_request', fn (Blueprint $table) => $table->dropColumn('approval_request_id'));
    }
};
