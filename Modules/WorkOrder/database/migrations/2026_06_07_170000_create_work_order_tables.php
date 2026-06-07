<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WO-01 Work Order module. Owns the field-execution lifecycle (create → assign →
 * in_progress → finalized) and evidence. WO must not own equipment serial
 * authority (OSR) nor customer/subscription state (HLD §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order', function (Blueprint $table) {
            $table->string('work_order_id')->primary();        // wo_...
            $table->string('operator_code')->index();
            $table->string('type');                            // INSTALLATION|SUPPORT|SHIFTING|RELOCATION|EQUIPMENT|NOC
            $table->string('status')->default('PENDING')->index(); // PENDING|ASSIGNED|IN_PROGRESS|FINALIZATION_PENDING|FINALIZED|CANCELLED
            $table->string('priority')->default('NORMAL');     // LOW|NORMAL|HIGH|URGENT
            $table->string('account_id')->nullable()->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('customer_id')->nullable();
            $table->string('homepass_id')->nullable();
            $table->string('tech_region_id')->nullable()->index();
            $table->string('contractor_id')->nullable();
            $table->string('team_id')->nullable();
            $table->string('assigned_technician_id')->nullable();
            $table->string('source_type')->nullable();         // TICKET | SUBSCRIPTION_OP | FULFILLMENT | MANUAL
            $table->string('source_ref')->nullable()->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('resolution_code')->nullable();
            $table->json('findings')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('wo_status_history', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('work_order_id')->index();
            $table->string('prev_status')->nullable();
            $table->string('new_status');
            $table->string('reason')->nullable();
            $table->string('changed_by')->nullable();
            $table->timestamp('changed_at')->useCurrent();

            $table->foreign('work_order_id')->references('work_order_id')->on('work_order')->cascadeOnDelete();
        });

        Schema::create('wo_note', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('work_order_id')->index();
            $table->text('body');
            $table->string('author_id')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('work_order_id')->on('work_order')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_note');
        Schema::dropIfExists('wo_status_history');
        Schema::dropIfExists('work_order');
    }
};
