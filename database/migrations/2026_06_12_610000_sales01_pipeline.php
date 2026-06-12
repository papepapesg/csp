<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SALES-01 commercial pre-order pipeline. The existing sales_lead was a thin funnel; this adds
 * the DD's full model — sales territories, the rich lead lifecycle, assignment history, sales
 * activities, package interest, the lead→FUL-02 conversion link, and immutable attribution
 * events (the commission basis). SALES-01 owns the pipeline; FUL-02 owns submitted orders and
 * ILM owns customers — SALES-01 only stores references after conversion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_territory', function (Blueprint $table) {
            $table->string('territory_id')->primary();           // terr_...
            $table->string('operator_code')->index();
            $table->string('territory_code');
            $table->string('name');
            $table->string('tech_region_code')->nullable();      // optional ILM-CFG-02 link
            $table->string('franchise_contractor_id')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata_json')->nullable();           // boundary polygon, route hints
            $table->timestamps();

            $table->unique(['operator_code', 'territory_code'], 'sales_territory_unique');
        });

        Schema::table('sales_lead', function (Blueprint $table) {
            $table->string('lead_number')->nullable()->after('operator_code'); // SL-WIK-2026-000042
            $table->string('source_channel')->nullable()->after('source');     // DOOR_TO_DOOR|FRANCHISE_WALKIN|CALLBACK|REFERRAL
            $table->string('prospect_name')->nullable()->after('name');
            $table->string('primary_phone')->nullable()->after('prospect_name');
            $table->string('territory_id')->nullable()->after('territory');
            $table->decimal('geo_lat', 10, 6)->nullable()->after('territory_id');
            $table->decimal('geo_lng', 10, 6)->nullable()->after('geo_lat');
            $table->boolean('consent_captured')->default(false)->after('geo_lng');
            $table->string('duplicate_risk')->default('NONE')->after('consent_captured'); // NONE|POSSIBLE_CUSTOMER|POSSIBLE_LEAD
            $table->string('created_by_agent_id')->nullable()->after('assigned_agent');

            $table->unique(['operator_code', 'lead_number'], 'sales_lead_number_unique');
        });

        Schema::create('sales_lead_assignment', function (Blueprint $table) {
            $table->string('assignment_id')->primary();          // assn_...
            $table->string('lead_id')->index();
            $table->string('operator_code')->index();
            $table->string('assigned_agent_id')->nullable();
            $table->string('assigned_team_id')->nullable();
            $table->string('assigned_by_user_id')->nullable();
            $table->string('reason_code')->nullable();           // TERRITORY_DEFAULT | MANUAL | REASSIGN
            $table->boolean('active')->default(true);
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->index(['lead_id', 'active']);
        });

        Schema::create('sales_activity', function (Blueprint $table) {
            $table->string('activity_id')->primary();            // sact_...
            $table->string('lead_id')->index();
            $table->string('operator_code')->index();
            $table->string('agent_id')->nullable();
            $table->string('activity_type');                     // VISIT | CALL | SMS | FOLLOW_UP | CONVERSION
            $table->string('outcome_code')->nullable();          // INTERESTED | NOT_INTERESTED | UNREACHABLE | ...
            $table->text('notes')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('sales_lead_package_interest', function (Blueprint $table) {
            $table->string('interest_id')->primary();            // spi_...
            $table->string('lead_id')->index();
            $table->string('operator_code')->index();
            $table->string('package_id');
            $table->integer('priority')->default(1);
            $table->timestamps();
        });

        Schema::create('sales_conversion', function (Blueprint $table) {
            $table->string('conversion_id')->primary();          // conv_...
            $table->string('lead_id')->index();
            $table->string('operator_code')->index();
            $table->string('order_id')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('subscription_id')->nullable();
            $table->string('converted_by_agent_id')->nullable();
            $table->timestamp('converted_at');
            $table->timestamps();
        });

        // Immutable attribution events — the commission basis (SALES-5). Append-only.
        Schema::create('sales_attribution_event', function (Blueprint $table) {
            $table->string('attribution_event_id')->primary();   // satt_...
            $table->string('operator_code')->index();
            $table->string('lead_id')->index();
            $table->string('order_id')->nullable();
            $table->string('agent_id')->nullable();
            $table->string('team_id')->nullable();
            $table->string('franchise_contractor_id')->nullable();
            $table->string('territory_code')->nullable();
            $table->string('event_type');                        // LEAD_CONVERTED | ...
            $table->json('payload_json')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_attribution_event');
        Schema::dropIfExists('sales_conversion');
        Schema::dropIfExists('sales_lead_package_interest');
        Schema::dropIfExists('sales_activity');
        Schema::dropIfExists('sales_lead_assignment');
        Schema::table('sales_lead', function (Blueprint $table) {
            $table->dropColumn(['lead_number', 'source_channel', 'prospect_name', 'primary_phone', 'territory_id', 'geo_lat', 'geo_lng', 'consent_captured', 'duplicate_risk', 'created_by_agent_id']);
        });
        Schema::dropIfExists('sales_territory');
    }
};
