<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FE-CH-USSD-01: config-driven USSD menus + richer session + per-request trace. The existing
 * ussd_session held a hardcoded menu state; the DD makes menus data (ussd_menu_definition,
 * per operator + language) so a new market or wording is a row edit, not code. ussd_request_log
 * captures every gateway request/response for dispute handling. USSD remains a thin channel
 * adapter — it calls owning module APIs and never owns billing/subscription/ticket logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ussd_session', function (Blueprint $table) {
            $table->string('gateway_session_id')->nullable()->after('operator_code');
            $table->string('customer_id')->nullable()->after('msisdn');
            $table->string('account_id')->nullable()->after('customer_id');
            $table->string('language_code')->default('en')->after('account_id');
            $table->string('current_menu_code')->default('MAIN')->after('language_code');
            $table->json('session_data_json')->nullable()->after('current_menu_code');
            $table->string('status')->default('ACTIVE')->after('session_data_json'); // ACTIVE|ENDED|TIMED_OUT|FAILED
            $table->timestamp('started_at')->nullable()->after('status');
            $table->timestamp('last_seen_at')->nullable()->after('started_at');
            $table->timestamp('ended_at')->nullable()->after('last_seen_at');

            $table->index(['msisdn', 'status']);
            $table->index(['last_seen_at', 'status']);
        });

        Schema::create('ussd_menu_definition', function (Blueprint $table) {
            $table->string('menu_def_id')->primary();             // umd_...
            $table->string('operator_code');
            $table->string('language_code');
            $table->string('menu_code');                          // MAIN | SUPPORT | LANGUAGE | ...
            $table->text('menu_text');                            // screen template
            $table->json('options_json');                         // {"1":"BALANCE","2":"PAY",...}
            $table->boolean('requires_customer')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['operator_code', 'language_code', 'menu_code'], 'umd_unique');
        });

        Schema::create('ussd_request_log', function (Blueprint $table) {
            $table->string('request_log_id')->primary();          // url_...
            $table->string('session_id')->index();
            $table->string('operator_code')->index();
            $table->string('gateway_request_id')->nullable();
            $table->text('input_text')->nullable();
            $table->string('response_type');                      // CON | END
            $table->text('response_text');
            $table->string('menu_code')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamp('created_at');

            $table->index(['session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_request_log');
        Schema::dropIfExists('ussd_menu_definition');
        Schema::table('ussd_session', function (Blueprint $table) {
            $table->dropColumn(['gateway_session_id', 'customer_id', 'account_id', 'language_code', 'current_menu_code', 'session_data_json', 'status', 'started_at', 'last_seen_at', 'ended_at']);
        });
    }
};
