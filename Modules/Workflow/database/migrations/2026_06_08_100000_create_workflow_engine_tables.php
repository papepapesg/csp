<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOUNDATION_CAMUNDA — config-driven workflow engine (Laravel-native).
 *
 * Flows are DATA, not code: a process_definition stores a node/edge graph
 * (React-Flow compatible) authored in the Backoffice studio. The engine executes
 * instances by handing service tasks to module workers via the external-task
 * pattern (fetchAndLock/complete), exactly like Camunda — so a new operator/market
 * is a new definition row, never a code change (CAM-BPMN-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Process definition (deployed flow). operator_code NULL = global default.
        Schema::create('process_definition', function (Blueprint $table) {
            $table->string('definition_id')->primary();        // pdef_...
            $table->string('process_key')->index();            // stable key e.g. sub-activate (CAM-BPMN-4)
            $table->unsignedInteger('version')->default(1);
            $table->string('operator_code')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('graph');                             // { nodes:[...], edges:[...] } (React Flow)
            $table->string('status')->default('DRAFT')->index(); // DRAFT|DEPLOYED|RETIRED
            $table->string('created_by')->nullable();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();

            $table->unique(['process_key', 'version', 'operator_code']);
        });

        // Process instance (one running execution).
        Schema::create('process_instance', function (Blueprint $table) {
            $table->string('instance_id')->primary();          // pi_...
            $table->string('definition_id')->index();
            $table->string('process_key')->index();
            $table->unsignedInteger('definition_version');
            $table->string('operator_code')->nullable()->index();
            $table->string('business_key')->nullable()->index(); // aggregate id (CAM-START-1)
            $table->json('variables')->nullable();             // ids + control flags only (CAM-START-4)
            $table->string('status')->default('RUNNING')->index(); // RUNNING|COMPLETED|FAILED|CANCELLED|SUSPENDED
            $table->json('active_nodes')->nullable();          // nodes currently waiting
            $table->string('correlation_id')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        // External task — work item a module worker picks up by topic (CAM-WORKER-*).
        Schema::create('workflow_external_task', function (Blueprint $table) {
            $table->string('task_id')->primary();              // et_...
            $table->string('instance_id')->index();
            $table->string('node_id');
            $table->string('topic')->index();                  // one topic per business action (CAM-WORKER-3)
            $table->string('operator_code')->nullable()->index();
            $table->string('business_key')->nullable();
            $table->json('variables')->nullable();
            $table->string('status')->default('CREATED')->index(); // CREATED|LOCKED|COMPLETED|FAILED|INCIDENT
            $table->string('worker_id')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->unsignedInteger('retries')->default(3);
            $table->text('error_message')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Human (user) task.
        Schema::create('workflow_user_task', function (Blueprint $table) {
            $table->string('task_id')->primary();              // ut_...
            $table->string('instance_id')->index();
            $table->string('node_id');
            $table->string('name');
            $table->string('candidate_group')->nullable()->index(); // maps to RBAC/EM group (CAM-USER-1)
            $table->string('assignee')->nullable()->index();
            $table->json('variables')->nullable();
            $table->string('status')->default('OPEN')->index(); // OPEN|CLAIMED|COMPLETED|CANCELLED
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        // Message catch subscription (workflow waits for a correlated message).
        Schema::create('workflow_message_subscription', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->index();
            $table->string('node_id');
            $table->string('message_name')->index();
            $table->string('correlation_key')->nullable()->index();
            $table->timestamps();
        });

        // Timer (workflow waits until fire_at).
        Schema::create('workflow_timer', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->index();
            $table->string('node_id');
            $table->timestamp('fire_at')->index();
            $table->string('status')->default('PENDING')->index(); // PENDING|FIRED|CANCELLED
            $table->timestamps();
        });

        // Activity log — every engine event, for live process tracing (IT-Ops).
        Schema::create('workflow_activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('instance_id')->index();
            $table->string('node_id')->nullable();
            $table->string('node_type')->nullable();
            $table->string('event');                           // INSTANCE_STARTED|NODE_ENTER|TASK_CREATED|TASK_COMPLETED|...
            $table->json('data')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_activity_log');
        Schema::dropIfExists('workflow_timer');
        Schema::dropIfExists('workflow_message_subscription');
        Schema::dropIfExists('workflow_user_task');
        Schema::dropIfExists('workflow_external_task');
        Schema::dropIfExists('process_instance');
        Schema::dropIfExists('process_definition');
    }
};
