<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIP-04 Bundle Launch + SIP-05 Promotional Campaigns (MVP).
 *
 * SIP-04: a commercial bundle is a launchable offer composed of SIP-01 package
 * references, with availability gating (channel/region/franchise), optional
 * SIP-03 discount rules, controlled bundle-to-bundle migration paths, and an
 * auditable launch-check trail. Lifecycle DRAFT → READY_FOR_REVIEW → APPROVED →
 * ACTIVE → SUSPENDED/RETIRED (+ REJECTED/CANCELLED).
 *
 * SIP-05: a campaign is a time-bound program with offers, targeting rules,
 * channel governance and per-participant redemption tracking; redemption binds
 * to a SIP-03 discount assignment. Extends the existing promo_campaign master.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- SIP-04 §6.1 bundle master ---
        Schema::create('commercial_bundle', function (Blueprint $table) {
            $table->string('bundle_id')->primary();            // bun_...
            $table->string('operator_code')->index();
            $table->string('bundle_code');
            $table->string('display_name');
            $table->string('description')->nullable();
            $table->string('status')->default('DRAFT')->index(); // DRAFT|READY_FOR_REVIEW|APPROVED|ACTIVE|SUSPENDED|RETIRED|REJECTED|CANCELLED
            $table->string('bundle_type')->default('GENERAL'); // ACQUISITION|RETENTION|MIGRATION|BUSINESS|STAFF|GENERAL
            $table->string('currency_code', 3)->default('KES');
            $table->date('launch_date')->nullable();
            $table->date('retire_date')->nullable();
            $table->string('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'bundle_code']);  // R-SIP-BUN-01
            $table->index(['operator_code', 'status', 'launch_date']);
        });

        // §6.2 packages included in the bundle.
        Schema::create('commercial_bundle_component', function (Blueprint $table) {
            $table->string('component_id')->primary();         // bcomp_...
            $table->string('bundle_id')->index();
            $table->string('package_ref');                     // SIP-01 package id/code
            $table->string('package_version_id')->nullable();
            $table->string('component_role')->default('PRIMARY'); // PRIMARY|ADDON|OPTIONAL|PROMOTIONAL
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('mandatory')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('package_ref');
        });

        // §6.3 where/how the bundle can be sold.
        Schema::create('commercial_bundle_availability', function (Blueprint $table) {
            $table->string('availability_id')->primary();      // bav_...
            $table->string('bundle_id')->index();
            $table->string('country_code', 2)->default('KE');
            $table->string('region_code')->nullable();
            $table->string('franchise_id')->nullable()->index();
            $table->string('tech_region_code')->nullable();
            $table->string('channel_code');                    // BACKOFFICE|SALES_APP|SELF_CARE|USSD|PARTNER_API
            $table->string('status')->default('ACTIVE');       // ACTIVE|SUSPENDED|RETIRED
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['bundle_id', 'status']);
            $table->index(['country_code', 'region_code', 'channel_code', 'status']);
        });

        // §6.4 discount assignment rules attached to the bundle (SIP-03 on order).
        Schema::create('commercial_bundle_discount_rule', function (Blueprint $table) {
            $table->string('discount_rule_id')->primary();     // bdr_...
            $table->string('bundle_id')->index();
            $table->string('discount_code')->index();          // PLM-CFG-04 code
            $table->string('assignment_scope_type')->default('ORDER');
            $table->string('validity_mode')->default('ORDER_ONLY'); // ORDER_ONLY|FIXED_WINDOW|DURATION_FROM_ACTIVATION
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('stacking_group_code')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
        });

        // §6.5 allowed bundle-to-bundle movement rules.
        Schema::create('commercial_bundle_migration_rule', function (Blueprint $table) {
            $table->string('migration_rule_id')->primary();    // bmr_...
            $table->string('operator_code')->index();
            $table->string('source_bundle_id')->index();
            $table->string('target_bundle_id')->index();
            $table->string('movement_type');                   // UPGRADE|DOWNGRADE|MIGRATION|RETENTION|FORCED_RETIREMENT
            $table->json('allowed_channel_json')->nullable();
            $table->boolean('requires_customer_consent')->default(true);
            $table->boolean('requires_wo')->default(false);
            $table->string('fee_policy_code')->default('NO_FEE');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index(['source_bundle_id', 'target_bundle_id', 'status']);
        });

        // §6.6 auditable launch validation findings.
        Schema::create('commercial_bundle_launch_check', function (Blueprint $table) {
            $table->string('check_id')->primary();             // blc_...
            $table->string('bundle_id')->index();
            $table->string('check_code');                      // PACKAGE_ACTIVE | MANDATORY_COMPONENT | DISCOUNT_ACTIVE
            $table->string('check_status');                    // PASS | WARN | FAIL
            $table->string('message');
            $table->json('source_ref_json')->nullable();
            $table->timestamp('checked_at')->useCurrent();

            $table->index(['bundle_id', 'check_status']);
        });

        // --- SIP-05: extend the campaign master to the DD shape ---
        Schema::table('promo_campaign', function (Blueprint $table) {
            $table->string('campaign_type')->default('ACQUISITION')->after('name'); // §3 types
            $table->string('owner_team_code')->nullable()->after('segment');
            $table->decimal('budget_limit_amount', 18, 2)->nullable()->after('owner_team_code');
            $table->string('currency_code', 3)->default('KES')->after('budget_limit_amount');
            $table->unsignedInteger('max_participants')->nullable()->after('currency_code');
            $table->string('created_by_user_id')->nullable()->after('max_participants');
        });

        // §6.2 what the campaign offers.
        Schema::create('promotion_campaign_offer', function (Blueprint $table) {
            $table->string('offer_id')->primary();             // pco_...
            $table->string('campaign_id')->index();
            $table->string('offer_type');                      // DISCOUNT|BUNDLE|PACKAGE|MESSAGE_ONLY
            $table->string('discount_code')->nullable();       // PLM-CFG-04
            $table->string('bundle_code')->nullable();         // SIP-04
            $table->string('package_ref')->nullable();         // SIP-01
            $table->string('assignment_scope_type')->nullable(); // SIP-03 scope when discount
            $table->string('validity_mode')->default('CAMPAIGN_WINDOW'); // CAMPAIGN_WINDOW|DURATION_FROM_ASSIGNMENT|ORDER_ONLY
            $table->unsignedInteger('duration_days')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });

        // §6.3 who is eligible.
        Schema::create('promotion_campaign_target_rule', function (Blueprint $table) {
            $table->string('target_rule_id')->primary();       // pct_...
            $table->string('campaign_id')->index();
            $table->string('rule_type');                       // CUSTOMER_SEGMENT|PACKAGE|REGION|FRANCHISE|CHANNEL|TENURE|PAYMENT_STATUS|CUSTOM
            $table->string('operator')->default('IN');         // IN|NOT_IN|EQ|GTE|LTE|BETWEEN
            $table->json('rule_value_json');
            $table->boolean('hard_exclusion')->default(true);  // failed rule blocks eligibility
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index(['campaign_id', 'status', 'display_order']);
        });

        // §6.4 channels allowed to present/redeem.
        Schema::create('promotion_campaign_channel', function (Blueprint $table) {
            $table->string('channel_id')->primary();           // pcc_...
            $table->string('campaign_id')->index();
            $table->string('channel_code');                    // BACKOFFICE|SALES_APP|SELF_CARE|USSD|PARTNER_API
            $table->boolean('requires_agent_attribution')->default(false);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();

            $table->index(['campaign_id', 'channel_code', 'status']);
        });

        // §6.5 one participation per participant: prevents duplicate redemption.
        Schema::create('promotion_campaign_participation', function (Blueprint $table) {
            $table->string('participation_id')->primary();     // pcp_...
            $table->string('campaign_id')->index();
            $table->string('operator_code')->index();
            $table->string('participant_type');                // LEAD|ORDER|CUSTOMER|ACCOUNT|SUBSCRIPTION
            $table->string('participant_ref_id');
            $table->string('customer_id')->nullable()->index();
            $table->string('franchise_id')->nullable()->index();
            $table->string('agent_user_id')->nullable();
            $table->string('status')->default('ELIGIBLE');     // ELIGIBLE|SELECTED|REDEEMED|REJECTED|CANCELLED
            $table->string('assignment_id')->nullable();       // SIP-03 discount assignment
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'participant_type', 'participant_ref_id']);
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_campaign_participation');
        Schema::dropIfExists('promotion_campaign_channel');
        Schema::dropIfExists('promotion_campaign_target_rule');
        Schema::dropIfExists('promotion_campaign_offer');
        Schema::table('promo_campaign', fn (Blueprint $t) => $t->dropColumn([
            'campaign_type', 'owner_team_code', 'budget_limit_amount', 'currency_code', 'max_participants', 'created_by_user_id',
        ]));
        Schema::dropIfExists('commercial_bundle_launch_check');
        Schema::dropIfExists('commercial_bundle_migration_rule');
        Schema::dropIfExists('commercial_bundle_discount_rule');
        Schema::dropIfExists('commercial_bundle_availability');
        Schema::dropIfExists('commercial_bundle_component');
        Schema::dropIfExists('commercial_bundle');
    }
};
