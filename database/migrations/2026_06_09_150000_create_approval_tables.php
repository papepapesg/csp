<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-CFG-04 Approval Workflow Catalog. approval_definition is the operator-scoped,
 * data-driven policy: for an entity_type, when does an action need approval and by
 * which role(s), with optional amount thresholds. approval_request is the runtime
 * lifecycle (PENDING -> APPROVED/REJECTED) any module raises before committing a
 * sensitive action (adjustments, dunning overrides, large discounts, ...).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_definition', function (Blueprint $table) {
            $table->string('definition_id')->primary();         // appd_...
            $table->string('operator_code')->index();
            $table->string('entity_type');                      // ADJUSTMENT | DISCOUNT | RESTRICTION_OVERRIDE | ...
            $table->string('action')->nullable();
            $table->decimal('threshold_amount', 14, 2)->nullable(); // require approval at/above
            $table->json('approver_roles');                     // ["BILLING_LEAD"]
            $table->unsignedInteger('required_approvals')->default(1);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['operator_code', 'entity_type', 'action']);
        });

        Schema::create('approval_request', function (Blueprint $table) {
            $table->string('request_id')->primary();            // appr_...
            $table->string('operator_code')->index();
            $table->string('entity_type')->index();
            $table->string('action')->nullable();
            $table->string('entity_ref')->nullable()->index();
            $table->decimal('amount', 14, 2)->nullable();
            $table->json('payload')->nullable();
            $table->string('status')->default('PENDING')->index(); // PENDING | APPROVED | REJECTED | AUTO_APPROVED
            $table->json('approver_roles')->nullable();
            $table->unsignedInteger('required_approvals')->default(1);
            $table->unsignedInteger('approvals_count')->default(0);
            $table->string('requested_by')->nullable();
            $table->string('decided_by')->nullable();
            $table->string('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_request');
        Schema::dropIfExists('approval_definition');
    }
};
