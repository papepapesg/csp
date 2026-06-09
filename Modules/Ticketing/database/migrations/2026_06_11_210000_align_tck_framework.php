<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01 alignment: the config-driven category catalog (§7.6) that gates WO creation
 * (TCK-3 only configured categories with wo_allowed may create a WO), plus the reopen
 * counter (§8.8) and comment visibility (§7.3 INTERNAL vs CUSTOMER_VISIBLE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_category_catalog', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('category_code');                    // NO_INTERNET, BILLING_DISPUTE, ...
            $table->string('display_name');
            $table->string('type_code')->nullable();            // TECHNICAL_SUPPORT, BILLING_COMPLAINT, ...
            $table->string('default_priority')->default('NORMAL');
            $table->string('default_queue')->nullable();
            $table->string('default_sla_policy')->nullable();
            $table->boolean('wo_allowed')->default(false);      // TCK-3: may this category create a WO?
            $table->string('default_wo_kind')->nullable();      // SUPPORT | SHIFTING | ...
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'category_code']);
        });

        Schema::table('ticket', function (Blueprint $table) {
            $table->unsignedInteger('reopened_count')->default(0)->after('resolution_code');
            $table->timestamp('cancelled_at')->nullable()->after('closed_at');
        });

        Schema::table('ticket_comment', function (Blueprint $table) {
            $table->string('visibility')->default('INTERNAL')->after('author_id'); // INTERNAL | CUSTOMER_VISIBLE
        });
    }

    public function down(): void
    {
        Schema::table('ticket_comment', fn (Blueprint $t) => $t->dropColumn('visibility'));
        Schema::table('ticket', fn (Blueprint $t) => $t->dropColumn(['reopened_count', 'cancelled_at']));
        Schema::dropIfExists('ticket_category_catalog');
    }
};
