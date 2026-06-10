<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-02-GEN-01 structured invoice lines + operator grouping policy.
 *
 * The line builder (R-GEN-01-L) produces a SUMMARY/DETAIL hierarchy: each
 * SUMMARY line shows on the summary page, each DETAIL line is a sub-breakdown
 * attached to its parent. Charges are typed (RECURRING vs USAGE vs ONE_OFF) and
 * classified (service_category / package / wallet), so an operator's grouping
 * policy can fold them — or split them into one invoice per group.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line', function (Blueprint $table) {
            $table->string('line_type')->default('SUMMARY')->after('invoice_id'); // SUMMARY | DETAIL
            $table->string('parent_summary_line_id')->nullable()->after('line_type'); // DETAIL → its SUMMARY (R-GEN-01-L-1)
            $table->string('service_category_code')->nullable()->after('parent_summary_line_id'); // BIL-01 charge classification
            $table->string('package_ref')->nullable()->after('service_category_code');
            $table->string('wallet_type_code')->nullable()->after('package_ref');
            $table->unsignedInteger('sort_order')->default(0)->after('wallet_type_code'); // R-GEN-01-L-4
            $table->json('tax_breakdown')->nullable()->after('tax_amount'); // per-line components (R-GEN-01-L-2)
        });

        // The invoice records which grouping produced it (audit + read API).
        Schema::table('invoice', function (Blueprint $table) {
            $table->string('grouping_dimension')->nullable()->after('type'); // SINGLE | WALLET | PACKAGE | SERVICE_CATEGORY
            $table->string('grouping_key_values')->nullable()->after('grouping_dimension'); // R-GEN-01-F-5
        });

        // Operator grouping policy per billing trigger (R-GEN-01-C-3 / K-5).
        Schema::create('invoice_grouping_config', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('trigger_code');        // CYCLE_POSTPAID | ONE_OFF_INVOICE | PRO_FORMA_CYCLE
            $table->string('grouping_dimension');  // SINGLE | WALLET | PACKAGE | SERVICE_CATEGORY
            $table->timestamps();
            $table->unique(['operator_code', 'trigger_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_grouping_config');
        Schema::table('invoice', function (Blueprint $table) {
            $table->dropColumn(['grouping_dimension', 'grouping_key_values']);
        });
        Schema::table('invoice_line', function (Blueprint $table) {
            $table->dropColumn(['line_type', 'parent_summary_line_id', 'service_category_code', 'package_ref', 'wallet_type_code', 'sort_order', 'tax_breakdown']);
        });
    }
};
