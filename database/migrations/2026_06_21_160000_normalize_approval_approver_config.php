<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-04 normalisation. The approver configuration (who approves, the quorum, self-approval) is
 * owned solely by approval_stage now. The flat approver_roles / required_approvals / allow_requester
 * columns on approval_definition were a redundant second source of truth (they only mirrored stage 1)
 * — drop them. The same columns on approval_request were a redundant copy of the active stage; the
 * request keeps stages_snapshot (the frozen chain) + current_stage + approvals_count, from which the
 * active stage is derived. approval_definition is now purely "WHEN approval is needed"; approval_stage
 * is "WHO approves, in order".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_definition', function (Blueprint $table) {
            $table->dropColumn(['approver_roles', 'required_approvals', 'allow_requester']);
        });
        Schema::table('approval_request', function (Blueprint $table) {
            $table->dropColumn(['approver_roles', 'required_approvals', 'allow_requester']);
        });
    }

    public function down(): void
    {
        Schema::table('approval_definition', function (Blueprint $table) {
            $table->json('approver_roles')->nullable();
            $table->unsignedInteger('required_approvals')->default(1);
            $table->boolean('allow_requester')->default(false);
        });
        Schema::table('approval_request', function (Blueprint $table) {
            $table->json('approver_roles')->nullable();
            $table->unsignedInteger('required_approvals')->default(1);
            $table->boolean('allow_requester')->default(false);
        });
    }
};
