<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EM-03 CVM full model. The existing cvm_activity was an offer-on-activity shim; the DD
 * separates the concerns into five tables:
 *   cvm_customer_signal_profile  aggregated churn/upsell signals + scores
 *   cvm_segment_membership       current CVM segment placement (Drools-driven)
 *   cvm_activity                 an outreach/retention/recovery task (extended to the DD shape)
 *   cvm_offer_instance           one offer proposed to a customer (with EM-CFG-04 approval ref)
 *   cvm_outcome                  the final outcome of an activity/offer
 * EM-03 owns these; it calls SIP-03/SUB/BIL/NOT-01/CUST-INT-01 for the actual actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cvm_customer_signal_profile', function (Blueprint $table) {
            $table->string('signal_profile_id')->primary();      // csp_...
            $table->string('operator_code');
            $table->string('customer_id');
            $table->string('account_id')->nullable();
            $table->integer('active_subscription_count')->default(0);
            $table->integer('open_ticket_count')->default(0);
            $table->integer('dunning_level')->nullable();
            $table->integer('days_since_last_payment')->nullable();
            $table->integer('complaint_count_90d')->default(0);
            $table->decimal('churn_risk_score', 6, 2)->default(0);   // 0-100
            $table->decimal('upsell_score', 6, 2)->default(0);
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'customer_id'], 'csp_unique');
            $table->index(['operator_code', 'churn_risk_score']);
            $table->index(['operator_code', 'dunning_level']);
        });

        Schema::create('cvm_segment_membership', function (Blueprint $table) {
            $table->string('membership_id')->primary();          // csm_...
            $table->string('operator_code');
            $table->string('segment_code');                      // RETENTION_HIGH_RISK | WINBACK | UPSELL | VIP_CARE | ...
            $table->string('customer_id');
            $table->string('status')->default('ACTIVE');         // ACTIVE | EXPIRED | SUPPRESSED
            $table->string('reason_code')->nullable();
            $table->timestamp('entered_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'customer_id', 'segment_code'], 'csm_unique');
            $table->index(['operator_code', 'segment_code', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('cvm_offer_instance', function (Blueprint $table) {
            $table->string('offer_instance_id')->primary();      // cvo_...
            $table->string('operator_code');
            $table->string('activity_id')->nullable();
            $table->string('customer_id');
            $table->string('subscription_id')->nullable();
            $table->string('offer_type');                        // RETENTION_DISCOUNT | UPGRADE_OFFER | WINBACK_PACKAGE | GOODWILL_CREDIT | PAYMENT_REMINDER
            $table->string('campaign_code')->nullable();         // SIP-05
            $table->string('discount_ref')->nullable();          // SIP-03/DIS catalog ref
            $table->decimal('discount_percent', 6, 2)->nullable();
            $table->string('status')->default('DRAFT');          // DRAFT | PROPOSED | ACCEPTED | REJECTED | EXPIRED | APPLIED | FAILED
            $table->string('approval_request_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index('approval_request_id');
        });

        Schema::create('cvm_outcome', function (Blueprint $table) {
            $table->string('outcome_id')->primary();             // cvoo_...
            $table->string('operator_code');
            $table->string('activity_id')->nullable();
            $table->string('offer_instance_id')->nullable();
            $table->string('customer_id');
            $table->string('outcome_code');                      // ACCEPTED | REJECTED | NO_RESPONSE | PAID | UPGRADED | RECOVERED | CHURNED
            $table->string('owning_module_ref_type')->nullable(); // DISCOUNT_ASSIGNMENT | SUB_OPERATION | ...
            $table->string('owning_module_ref_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['outcome_code', 'created_at']);
        });

        // Extend cvm_activity to the DD shape (keep legacy columns nullable for any old rows).
        Schema::table('cvm_activity', function (Blueprint $table) {
            $table->string('activity_type')->nullable()->after('customer_id'); // RETENTION_CALL | PAYMENT_RECOVERY | UPSELL_OFFER | WINBACK | SERVICE_RECOVERY
            $table->string('account_id')->nullable()->after('customer_id');
            $table->string('source_event_ref')->nullable()->after('trigger_reason'); // idempotency
            $table->string('assigned_to_user_id')->nullable()->after('assigned_to');
            $table->string('assigned_team_id')->nullable()->after('assigned_to_user_id');
            $table->string('priority')->default('MEDIUM')->after('assigned_team_id'); // LOW|MEDIUM|HIGH|CRITICAL
            $table->timestamp('due_at')->nullable()->after('priority');
            $table->timestamp('closed_at')->nullable()->after('decided_at');

            $table->unique(['operator_code', 'source_event_ref'], 'cvm_activity_idem');
            $table->index(['operator_code', 'status', 'priority']);
        });
        // Legacy `type` was NOT NULL; the DD model uses activity_type, so relax it.
        DB::statement('ALTER TABLE cvm_activity ALTER COLUMN type DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('cvm_activity', function (Blueprint $table) {
            $table->dropUnique('cvm_activity_idem');
            $table->dropColumn(['activity_type', 'account_id', 'source_event_ref', 'assigned_to_user_id', 'assigned_team_id', 'priority', 'due_at', 'closed_at']);
        });
        Schema::dropIfExists('cvm_outcome');
        Schema::dropIfExists('cvm_offer_instance');
        Schema::dropIfExists('cvm_segment_membership');
        Schema::dropIfExists('cvm_customer_signal_profile');
    }
};
