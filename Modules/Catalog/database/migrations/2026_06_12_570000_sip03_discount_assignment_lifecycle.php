<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIP-03 discount assignment lifecycle. The existing discount_assignment was a thin grant
 * (scope + ref + active); the DD governs the full lifecycle: DRAFT / PENDING_APPROVAL / ACTIVE /
 * SUSPENDED / EXPIRED / CANCELLED / REJECTED, an EM-CFG-04 approval reference, validity windows,
 * reason/source/priority/stacking metadata, and an immutable status history. Bulk-assignment
 * batch tables are phased after the core path (DD MVP baseline) and are intentionally omitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_assignment', function (Blueprint $table) {
            $table->string('discount_id')->nullable()->after('discount_code');     // PLM-CFG-04 ref
            $table->string('scope_type')->nullable()->after('discount_id');          // CUSTOMER|ACCOUNT|SUBSCRIPTION|ORDER|PACKAGE|FRANCHISE|CAMPAIGN_COHORT
            $table->string('scope_ref_id')->nullable()->after('scope_type');
            $table->string('customer_id')->nullable()->after('scope_ref_id');
            $table->string('account_id')->nullable()->after('customer_id');
            $table->string('subscription_id')->nullable()->after('account_id');
            $table->string('package_ref')->nullable()->after('subscription_id');
            $table->string('campaign_id')->nullable()->after('campaign_code');
            $table->string('franchise_id')->nullable()->after('campaign_id');
            $table->string('reason_code')->nullable()->after('franchise_id');
            $table->string('source_channel')->nullable()->after('reason_code');      // BACKOFFICE|SALES_APP|CAMPAIGN|ASR|BATCH|API
            $table->date('valid_from')->nullable()->after('source_channel');
            $table->date('valid_to')->nullable()->after('valid_from');
            $table->string('status')->default('ACTIVE')->after('valid_to');          // DRAFT|PENDING_APPROVAL|ACTIVE|SUSPENDED|EXPIRED|CANCELLED|REJECTED
            $table->integer('assignment_priority')->default(100)->after('status');   // runtime ordering (DD `priority`)
            $table->string('stacking_group_code')->nullable()->after('assignment_priority');
            $table->string('approval_request_id')->nullable()->after('stacking_group_code');
            $table->json('metadata_json')->nullable()->after('approval_request_id');
            $table->string('created_by_user_id')->nullable()->after('metadata_json');
            $table->timestamp('activated_at')->nullable()->after('created_by_user_id');
            $table->timestamp('cancelled_at')->nullable()->after('activated_at');

            $table->index(['operator_code', 'status', 'valid_from', 'valid_to'], 'da_runtime_lookup');
            $table->index(['operator_code', 'scope_type', 'scope_ref_id', 'status'], 'da_scope_lookup');
            $table->index(['customer_id', 'status']);
            $table->index(['subscription_id', 'status']);
        });
        // Legacy rows carried `scope`/`scope_ref`; mirror them into the DD columns so the runtime
        // query is uniform.
        DB::statement("UPDATE discount_assignment SET scope_type = scope, scope_ref_id = scope_ref WHERE scope_type IS NULL");

        Schema::create('discount_assignment_status_history', function (Blueprint $table) {
            $table->string('history_id')->primary();             // dash_...
            $table->string('assignment_id')->index();
            $table->string('operator_code')->index();
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->string('reason_code')->nullable();
            $table->string('changed_by_user_id')->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['assignment_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_assignment_status_history');
        Schema::table('discount_assignment', function (Blueprint $table) {
            $table->dropColumn([
                'discount_id', 'scope_type', 'scope_ref_id', 'customer_id', 'account_id', 'subscription_id', 'package_ref',
                'campaign_id', 'franchise_id', 'reason_code', 'source_channel', 'valid_from', 'valid_to', 'status',
                'assignment_priority', 'stacking_group_code', 'approval_request_id', 'metadata_json', 'created_by_user_id',
                'activated_at', 'cancelled_at',
            ]);
        });
    }
};
