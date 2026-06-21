<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-04 staged approval chains. The flat "N approvals from one role pool" model could not
 * express a real approval HIERARCHY (manager THEN director) and counted raw approvals (so one
 * person could fill a 2-of quorum twice). This adds an ORDERED chain of stages per definition:
 *
 *   approval_definition (header)  --<  approval_stage[] (sequence 1..n)
 *
 * Each stage targets EITHER a platform role (ROLE: any of approver_roles) OR a specific named
 * person (USER: approver_user_ref / approver_email — e.g. a "director" who has no platform role,
 * just an invited lightweight login). A stage carries its own quorum (required_approvals) and its
 * own self-approval toggle (allow_requester). The request advances stage-by-stage; each stage's
 * quorum must be met by DISTINCT approvers before the next stage opens; the last stage flips the
 * request to APPROVED. A reject at any stage fails the whole chain.
 *
 * Back-compat: a definition with NO stage rows behaves exactly as before — a single implicit stage
 * built from the legacy approver_roles / required_approvals / allow_requester columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_stage', function (Blueprint $table) {
            $table->string('stage_id')->primary();              // appds_...
            $table->string('operator_code')->index();
            $table->string('definition_id')->index();           // FK-by-code to approval_definition
            $table->unsignedInteger('sequence');                // 1-based order in the chain
            $table->string('name')->nullable();                 // human label e.g. "Manager review"
            $table->string('approver_kind')->default('ROLE');   // ROLE | USER
            $table->json('approver_roles')->nullable();         // ROLE: any of these roles may fill a slot
            $table->string('approver_user_ref')->nullable();    // USER: the named person's user uid
            $table->string('approver_email')->nullable();       // USER: the named person's email (display + invite match)
            $table->unsignedInteger('required_approvals')->default(1); // distinct approvers needed in THIS stage
            $table->boolean('allow_requester')->default(false); // per-stage SoD toggle
            $table->timestamps();

            $table->unique(['definition_id', 'sequence']);
        });

        Schema::table('approval_request', function (Blueprint $table) {
            $table->unsignedInteger('current_stage')->default(1)->after('required_approvals'); // active stage
            $table->unsignedInteger('total_stages')->default(1)->after('current_stage');
            $table->json('stages_snapshot')->nullable()->after('total_stages'); // resolved chain captured at request time (immutable)
        });

        Schema::table('approval_decision', function (Blueprint $table) {
            $table->unsignedInteger('stage_sequence')->nullable()->after('decision'); // which stage this decision belongs to
        });
    }

    public function down(): void
    {
        Schema::table('approval_decision', fn (Blueprint $t) => $t->dropColumn('stage_sequence'));
        Schema::table('approval_request', fn (Blueprint $t) => $t->dropColumn(['current_stage', 'total_stages', 'stages_snapshot']));
        Schema::dropIfExists('approval_stage');
    }
};
