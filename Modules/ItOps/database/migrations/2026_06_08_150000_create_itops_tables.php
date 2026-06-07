<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IT-Ops observability: searchable structured logs, service/worker heartbeats,
 * and a service control flag (start/restart signalling). DD_QA/ops support.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_log', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->index();              // debug|info|warning|error|critical
            $table->string('channel')->nullable()->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('correlation_id')->nullable()->index();
            $table->timestamp('logged_at')->index();
        });

        Schema::create('service_heartbeat', function (Blueprint $table) {
            $table->string('service')->primary();              // workflow-worker | scheduler | outbox-dispatcher
            $table->string('instance_id')->nullable();
            $table->string('status')->default('UP');           // UP|DOWN|STARTING
            $table->json('metrics')->nullable();               // queue depth, processed, etc.
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('service_control', function (Blueprint $table) {
            $table->string('service')->primary();
            $table->string('command')->nullable();             // RESTART | PAUSE | RESUME
            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_control');
        Schema::dropIfExists('service_heartbeat');
        Schema::dropIfExists('system_log');
    }
};
