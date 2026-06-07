<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BIL-04 Dunning Engine state. One row per account in dunning. Tracks the
 * escalation level (0 none/cleared, 1 warning, 2 restricted, 3 suspended,
 * 4 terminated), when it entered that level, and the outstanding debt. The
 * escalation policy (levels, grace days, action) is configurable via the
 * rules.billing.dunning decision table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dunning_state', function (Blueprint $table) {
            $table->string('dunning_id')->primary();           // dun_...
            $table->string('operator_code')->index();
            $table->string('account_id')->index();
            $table->string('subscription_id')->nullable()->index();
            $table->unsignedTinyInteger('current_level')->default(0); // 0..4
            $table->timestamp('entered_level_at')->nullable();
            $table->decimal('outstanding_debt_amount', 14, 2)->default(0);
            $table->string('status')->default('ACTIVE')->index();     // ACTIVE | CLEARED
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_state');
    }
};
