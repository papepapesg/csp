<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-03 §8.8 rbac_change_audit — immutable audit trail for RBAC changes
 * (role/permission catalog edits, matrix changes, user role assignments). Used for
 * security audit, admin review, incident investigation and access reconstruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_change_audit', function (Blueprint $table) {
            $table->string('audit_id')->primary();              // rba_...
            $table->string('operator_code')->nullable()->index();
            $table->string('change_type');                      // ROLE_CREATED | ROLE_PERMISSIONS_SYNCED | PERMISSION_CREATED | USER_ROLE_ASSIGNED
            $table->string('target_type');                      // ROLE | PERMISSION | USER_ROLE
            $table->string('target_id');                        // role code / permission code / user uid
            $table->string('actor_user_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json');
            $table->string('reason_code')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_change_audit');
    }
};
