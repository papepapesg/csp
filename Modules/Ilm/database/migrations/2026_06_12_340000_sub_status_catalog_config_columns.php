<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ILM-CFG-01 §3.1 — the sub-status catalog is the operator's config that *drives* a
 * status change: which main status it clones from (already present as main_status),
 * whether it requires an approval reference (R-ILM-S-2), whether it affects provisioning
 * (R-ILM-S-3, carried on CustomerAccountStatusChanged), and whether it is customer-visible.
 * These are config, not code — an operator changes a sub-status's behaviour by editing
 * its catalog row, no deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_sub_status_catalog', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('main_status');
            $table->json('approval_roles_jsonb')->nullable()->after('requires_approval');
            $table->boolean('affects_provisioning')->default(false)->after('approval_roles_jsonb');
            $table->boolean('customer_visible')->default(true)->after('affects_provisioning');
        });

        // R-ILM-S-2: the approval reference for a requires_approval transition is audited.
        Schema::table('account_status_history', function (Blueprint $table) {
            $table->string('approval_reference')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('account_status_history', fn (Blueprint $t) => $t->dropColumn('approval_reference'));
        Schema::table('customer_sub_status_catalog', fn (Blueprint $t) => $t->dropColumn(['requires_approval', 'approval_roles_jsonb', 'affects_provisioning', 'customer_visible']));
    }
};
