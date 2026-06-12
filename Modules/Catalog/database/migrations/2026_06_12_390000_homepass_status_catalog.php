<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RLM-CFG-01 §1 — HomePass status is NOT a hardcoded enum. It is a per-deployment
 * config catalog of status codes, each carrying semantic flags (is_initial, is_sellable,
 * is_active, is_terminal, triggers_lead_notification, blocks_soft_delete). Downstream
 * modules read the FLAGS, never the literal code — so a deployment can call its sellable
 * status RFS, LIVE, READY, anything, and the system behaves the same.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homepass_status_code', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('code');                                  // NPL | NSN | RFS | WAI | ACT | RETIRED | ...
            $table->string('description')->nullable();
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_sellable')->default(false);
            $table->boolean('is_active')->default(false);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('requires_approval_to_enter')->default(false);
            $table->boolean('triggers_lead_notification')->default(false);
            $table->boolean('blocks_soft_delete')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->primary(['operator_code', 'code']);
        });

        // R-RLM-CFG-01-H-6 latch: HomePassReachedSellable fires only on the FIRST sellable transition.
        Schema::table('homepass', function (Blueprint $table) {
            $table->boolean('has_been_sellable')->default(false)->after('has_been_active');
        });
    }

    public function down(): void
    {
        Schema::table('homepass', fn (Blueprint $t) => $t->dropColumn('has_been_sellable'));
        Schema::dropIfExists('homepass_status_code');
    }
};
