<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-03 RBAC catalog completion. Spatie remains the role-permission enforcement engine
 * (the API authorization mechanism). This adds the DD's missing pieces layered over it:
 *   rbac_user_scope_assignment  where a user's permissions apply (operator/franchise/region/...)
 *   rbac_role_meta              role family / display / status / keycloak mirror metadata
 *   rbac_permission_meta        module / action group / risk / scope_required metadata
 *   rbac_frontend_action        the frontend menu/action catalog (UI visibility, never security)
 *   rbac_role_frontend_action   precomputed role -> action visibility matrix
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rbac_user_scope_assignment', function (Blueprint $table) {
            $table->string('scope_assignment_id')->primary();    // usa_...
            $table->string('operator_code');
            $table->string('auth_user_id')->index();             // User.uid (Keycloak subject)
            $table->string('scope_type');                        // OPERATOR | FRANCHISE | TECH_REGION | CONTRACTOR | TEAM | CHANNEL | GLOBAL
            $table->string('scope_value');
            $table->string('scope_label')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('active')->default(true);
            $table->string('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['auth_user_id', 'scope_type', 'scope_value'], 'rbac_usa_unique');
            $table->index(['operator_code', 'auth_user_id', 'active']);
        });

        Schema::create('rbac_role_meta', function (Blueprint $table) {
            $table->string('role_code')->primary();
            $table->string('operator_code')->default('GLOBAL');
            $table->string('display_name')->nullable();
            $table->string('role_family')->nullable();           // CUSTOMER_CARE | BILLING | ...
            $table->text('description')->nullable();
            $table->string('status')->default('ACTIVE');         // DRAFT | ACTIVE | RETIRED
            $table->string('keycloak_role_name')->nullable();
            $table->timestamps();
        });

        Schema::create('rbac_permission_meta', function (Blueprint $table) {
            $table->string('permission_code')->primary();
            $table->string('module_code')->nullable();
            $table->string('action_group')->nullable();
            $table->string('risk_level')->default('LOW');        // LOW | MEDIUM | HIGH | CRITICAL
            $table->text('description')->nullable();
            $table->boolean('scope_required')->default(false);   // module APIs must verify scope when true
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('rbac_frontend_action', function (Blueprint $table) {
            $table->string('frontend_action_id')->primary();     // fea_...
            $table->string('app_code');                          // FE-APP-01 | FE-APP-02 | ...
            $table->string('action_code')->unique();             // backoffice.tickets.create
            $table->string('action_type');                       // MENU | SCREEN | BUTTON | TAB | FIELD
            $table->string('display_name');
            $table->string('required_permission_code')->nullable();
            $table->string('feature_flag')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('rbac_role_frontend_action', function (Blueprint $table) {
            $table->string('role_action_id')->primary();         // rfa_...
            $table->string('role_code')->index();
            $table->string('frontend_action_id')->index();
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->unique(['role_code', 'frontend_action_id'], 'rbac_rfa_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_role_frontend_action');
        Schema::dropIfExists('rbac_frontend_action');
        Schema::dropIfExists('rbac_permission_meta');
        Schema::dropIfExists('rbac_role_meta');
        Schema::dropIfExists('rbac_user_scope_assignment');
    }
};
