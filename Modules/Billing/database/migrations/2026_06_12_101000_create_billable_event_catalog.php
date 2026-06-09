<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-CFG-01 BillableEvent catalog — the operator-scoped registry of chargeable
 * events BIL-01 charges customers under. Owns trigger taxonomy, applicability,
 * signed-amount semantics and the finance-reporting sub-catalog (categories).
 * BIL-01 resolves matching ACTIVE events at runtime when a workflow's
 * bil01-emit-intent arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Finance-reporting classification (LIFECYCLE_FEE, PRORATION, REFUND…).
        Schema::create('billable_event_category', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('code');                              // uppercase snake-case, unique per operator
            $table->string('description');
            $table->string('gl_account_hint')->nullable();       // finance reconciliation hint, advisory only
            $table->boolean('is_credit')->default(false);        // true for refund / credit-issuing categories
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status')->default('ACTIVE');         // DRAFT | ACTIVE | RETIRED
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        // The catalog of chargeable events, operator-scoped.
        Schema::create('billable_event', function (Blueprint $table) {
            $table->string('id')->primary();                     // bev_...
            $table->string('operator_code');
            $table->string('code');                              // immutable audit-trail key, unique per operator
            $table->string('description');
            $table->string('category_code');                     // FK billable_event_category(operator_code, code)
            $table->json('service_refs')->nullable();            // ONE_SHOT Service code refs from PLM-CFG-01
            $table->string('currency', 3)->default('KES');       // derived from Service refs; immutable thereafter
            $table->string('applicability')->default('ANY');     // PREPAID_ONLY | POSTPAID_ONLY | ANY
            $table->string('amount_sign_policy')->default('POSITIVE_ONLY'); // POSITIVE_ONLY | NEGATIVE_ONLY | SIGNED
            $table->boolean('pay_first_required')->default(true);
            $table->string('trigger_type');                      // SAGA_INTENT | LIFECYCLE_EVENT | ADMIN_ACTION | CUSTOMER_PURCHASE | EXTERNAL_PAYMENT | SCHEDULED
            $table->string('trigger_intent_code')->nullable();   // required for SAGA_INTENT
            $table->string('trigger_event_type')->nullable();    // required for LIFECYCLE_EVENT
            $table->string('trigger_filter_drl')->nullable();    // optional rule name for extra filtering
            $table->string('trigger_schedule')->nullable();      // required for SCHEDULED (cron)
            $table->json('state_callback')->nullable();          // {transitionCode, targetStatus, callbackMetadata?} → SUB-LM-01
            $table->json('eligibility_franchise_refs')->nullable();
            $table->json('eligibility_package_refs')->nullable();
            $table->json('eligibility_segment_refs')->nullable();
            $table->unsignedInteger('display_order')->default(100);
            $table->string('status')->default('DRAFT');          // DRAFT | ACTIVE | RETIRED
            $table->text('notes')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
            $table->index(['operator_code', 'trigger_intent_code']);
            $table->index(['operator_code', 'category_code']);
            $table->index(['operator_code', 'applicability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billable_event');
        Schema::dropIfExists('billable_event_category');
    }
};
