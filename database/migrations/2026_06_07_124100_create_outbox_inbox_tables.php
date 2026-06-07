<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox / consumer inbox (FOUNDATION_KAFKA, HLD §6.6).
 *
 * The outbox row is written in the same DB transaction as the business change;
 * a dispatcher publishes committed rows to the event bus. The inbox deduplicates
 * received events so consumers stay idempotent across retries and replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();         // globally unique envelope id
            $table->string('event_type')->index();        // e.g. SubscriptionActivated
            $table->string('topic')->index();             // logical Kafka topic
            $table->string('aggregate_type')->nullable();
            $table->string('aggregate_id')->nullable()->index();
            $table->string('operator_code')->nullable()->index();
            $table->string('correlation_id')->nullable();
            $table->json('payload');
            $table->json('headers')->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('inbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->index();
            $table->string('consumer')->index();          // consumer group / handler
            $table->string('event_type')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'consumer']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_events');
        Schema::dropIfExists('outbox_events');
    }
};
