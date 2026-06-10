<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-ADJ-01: approval routing is a rules-engine decision
 * (rules.billing.adjustment-approval), resolved when the proposal is filed and
 * PINNED here — later policy edits never retro-affect in-flight proposals
 * (same philosophy as workflow instances pinning their definition).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->unsignedInteger('required_approvals')->nullable()->after('status'); // 0 = auto-approve
            $table->string('approval_rule_id')->nullable()->after('required_approvals'); // which rule decided (audit)
        });
    }

    public function down(): void
    {
        Schema::table('adjustment_request', function (Blueprint $table) {
            $table->dropColumn(['required_approvals', 'approval_rule_id']);
        });
    }
};
