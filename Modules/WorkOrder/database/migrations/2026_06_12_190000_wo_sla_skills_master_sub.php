<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WO-01-FRAMEWORK depth: SLA due-time capture, skills requirement for
 * skills-filtered auto-assign, and active master/sub work-order linkage.
 */
return new class extends Migration
{
    public function up(): void
    {
        // master_wo_id already exists (wo_support migration); add link_type + the rest.
        Schema::table('work_order', function (Blueprint $table) {
            $table->string('link_type')->nullable()->after('master_wo_id'); // PARENT_CHILD | DEPENDENCY
            $table->timestamp('sla_due_at')->nullable()->after('scheduled_at');
            $table->timestamp('first_response_at')->nullable()->after('assigned_at'); // SLA first-touch
            $table->json('required_skills')->nullable()->after('resolution_code');     // skills auto-assign filter
        });
    }

    public function down(): void
    {
        Schema::table('work_order', function (Blueprint $table) {
            $table->dropColumn(['link_type', 'sla_due_at', 'first_response_at', 'required_skills']);
        });
    }
};
