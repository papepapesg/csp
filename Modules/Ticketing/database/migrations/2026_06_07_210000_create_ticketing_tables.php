<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TCK-01 Ticketing & Case Management. TCK owns the case lifecycle (category,
 * priority, SLA, owner, queue, linked entities, comments, timeline, resolution)
 * and may create a WO; it must not own customer/WO/billing/subscription state
 * (HLD §6.7). ASR-01..04 are implemented as ticket categories (MVP baseline §3.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket', function (Blueprint $table) {
            $table->string('ticket_id')->primary();            // tck_...
            $table->string('operator_code')->index();
            $table->string('category');                        // TECHNICAL|BILLING|INFORMATION|COMPLAINT|SERVICE_REQUEST
            $table->string('subcategory')->nullable();
            $table->string('priority')->default('NORMAL');     // LOW|NORMAL|HIGH|URGENT
            $table->string('status')->default('OPEN')->index(); // OPEN|ASSIGNED|IN_PROGRESS|PENDING_WO|RESOLVED|CLOSED
            $table->string('customer_id')->nullable()->index();
            $table->string('account_id')->nullable()->index();
            $table->string('subscription_id')->nullable();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('queue')->nullable()->index();
            $table->string('assignee_id')->nullable()->index();
            $table->timestamp('sla_due_at')->nullable();
            $table->string('work_order_id')->nullable();
            $table->string('resolution_code')->nullable();
            $table->text('resolution_note')->nullable();
            $table->string('opened_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ticket_comment', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('ticket_id')->index();
            $table->string('author_id')->nullable();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->foreign('ticket_id')->references('ticket_id')->on('ticket')->cascadeOnDelete();
        });

        Schema::create('ticket_timeline', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('ticket_id')->index();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->string('actor_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ticket_id')->references('ticket_id')->on('ticket')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_timeline');
        Schema::dropIfExists('ticket_comment');
        Schema::dropIfExists('ticket');
    }
};
