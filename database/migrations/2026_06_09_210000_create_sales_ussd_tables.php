<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM-01 Franchise Management + SALES-01 Lead/Territory + USSD channel.
 * franchise (territory + commission), sales_lead (lead funnel with franchise/agent
 * attribution, NEW -> QUALIFIED -> CONVERTED/LOST), ussd_session (stateless
 * menu-driven self-care channel state keyed by gateway session id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise', function (Blueprint $table) {
            $table->string('franchise_id')->primary();          // frn_...
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('territory')->nullable();
            $table->string('owner_name')->nullable();
            $table->decimal('commission_rate', 6, 4)->default(0);
            $table->string('status')->default('ACTIVE');
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        Schema::create('sales_lead', function (Blueprint $table) {
            $table->string('lead_id')->primary();               // lead_...
            $table->string('operator_code')->index();
            $table->string('name');
            $table->string('msisdn');
            $table->string('source')->nullable();               // FIELD | CALL | WEB | USSD
            $table->string('territory')->nullable();
            $table->string('franchise_code')->nullable()->index();
            $table->string('assigned_agent')->nullable()->index();
            $table->string('homepass_id')->nullable();
            $table->string('package_ref')->nullable();
            $table->string('status')->default('NEW')->index();  // NEW|QUALIFIED|CONVERTED|LOST
            $table->string('converted_customer_id')->nullable();
            $table->string('lost_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('ussd_session', function (Blueprint $table) {
            $table->string('session_id')->primary();            // gateway session id
            $table->string('operator_code')->index();
            $table->string('msisdn')->index();
            $table->string('state')->default('MENU');           // current menu node
            $table->json('context')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_session');
        Schema::dropIfExists('sales_lead');
        Schema::dropIfExists('franchise');
    }
};
