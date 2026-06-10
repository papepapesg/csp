<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01 §7.1 SLA first-response tracking (first_response_due_at / first_response_at)
 * and §9.2 category-driven review gate on WO finalization (review_required → the
 * finalized ticket parks in UNDER_REVIEW instead of going straight to RESOLVED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket', function (Blueprint $table) {
            $table->timestamp('first_response_due_at')->nullable()->after('sla_due_at');
            $table->timestamp('first_response_at')->nullable()->after('first_response_due_at');
            // §9.2: set when a linked WO is cancelled — the ticket needs supervisor review.
            $table->boolean('requires_review')->default(false)->after('reopened_count');
        });

        Schema::table('ticket_category_catalog', function (Blueprint $table) {
            // §9.2: when a WO created from this category finalizes, route to UNDER_REVIEW
            // (supervisor confirms) rather than RESOLVED.
            $table->boolean('review_required')->default(false)->after('default_wo_kind');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_category_catalog', fn (Blueprint $t) => $t->dropColumn('review_required'));
        Schema::table('ticket', fn (Blueprint $t) => $t->dropColumn(['first_response_due_at', 'first_response_at', 'requires_review']));
    }
};
