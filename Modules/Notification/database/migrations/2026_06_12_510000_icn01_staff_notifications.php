<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ICN-01 Internal Communications — the staff-facing notification fabric. Strictly staff-scoped
 * (no customer tables, ever — that is DD_NOT-01's job). Seven tables per the DD plus a
 * staff_group_membership stand-in for the FOUNDATION_AUTH group-membership API:
 *
 *   staff_notification_template               operator-scoped template catalog (per channel variant)
 *   staff_notification_channel_config         operator DOMAIN config (enabled channels, priority, fallback, retry, ack window)
 *   staff_notification_adapter_binding        operator (channel)->(adapter_impl + opaque config) — provider endpoints / secrets refs
 *   staff_notification_user_pref              per-user DOMAIN overrides (channels, priority, quiet hours, suppression)
 *   staff_notification_user_channel_identity  per-user per-channel provider identifier (opaque identity)
 *   staff_notification                        one row per dispatch request
 *   staff_notification_delivery               one row per (recipient x channel) attempt; lifecycle state
 *   staff_group_membership                    FOUNDATION_AUTH group-membership stand-in (group_code -> user ids)
 *
 * Retires the staff_notification_stub pattern (FUL-02 §6.3, WO §9.1). Array-typed DD columns
 * (TEXT[]) are stored as json for portability; channel kinds are TEXT, never enums (regional
 * flexibility — new channels land without DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_notification_template', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('template_code');
            $table->string('channel');                       // EMAIL | SLACK | MSTEAMS | IN_APP_PUSH | ...
            $table->string('subject')->nullable();           // EMAIL only
            $table->text('body_template');                   // Mustache {{var}}
            $table->json('required_variables')->nullable();  // caller MUST supply
            $table->json('optional_variables')->nullable();
            $table->string('urgency_default')->default('medium'); // low | medium | high
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->primary(['operator_code', 'template_code', 'channel']);
            $table->index(['operator_code', 'enabled']);
        });

        Schema::create('staff_notification_channel_config', function (Blueprint $table) {
            $table->string('operator_code')->primary();
            $table->json('enabled_channels');                // ['EMAIL','SLACK','IN_APP_PUSH']
            $table->json('default_priority');                // ['IN_APP_PUSH','SLACK','EMAIL'] try-order
            $table->string('fallback_mode')->default('PARALLEL'); // PARALLEL | SEQUENTIAL_UNTIL_ACK | SEQUENTIAL_UNTIL_DISPATCH
            $table->integer('retry_max_attempts')->default(3);
            $table->json('retry_backoff_seconds');           // [30,120,600]
            $table->integer('ack_window_hours')->default(24);
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('staff_notification_adapter_binding', function (Blueprint $table) {
            $table->string('operator_code');
            $table->string('channel');
            $table->string('adapter_impl');                  // smtp-classic | slack-bot-api | msteams-incoming-webhook | ...
            $table->json('config_jsonb');                    // opaque to ICN-01; secret REFS only, never inline secrets
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->string('updated_by')->nullable();

            $table->primary(['operator_code', 'channel']);
            $table->index(['operator_code', 'enabled']);
        });

        Schema::create('staff_notification_user_pref', function (Blueprint $table) {
            $table->string('user_id')->primary();
            $table->string('operator_code');
            $table->json('enabled_channels')->nullable();    // null = operator default
            $table->json('preferred_priority')->nullable();  // null = operator default
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->string('quiet_hours_timezone')->nullable();
            $table->json('suppress_channels')->nullable();   // never deliver these to the user
            $table->timestamp('updated_at')->nullable();
            $table->string('updated_by')->nullable();

            $table->index('operator_code');
        });

        Schema::create('staff_notification_user_channel_identity', function (Blueprint $table) {
            $table->string('user_id');
            $table->string('channel');
            $table->string('operator_code');
            $table->json('identity_jsonb');                  // adapter-specific opaque identifier
            $table->string('source')->default('admin');      // self | admin | auto-resolved-from-auth | directory-sync
            $table->boolean('verified')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->string('updated_by')->nullable();

            $table->primary(['user_id', 'channel']);
            $table->index('operator_code');
            $table->index('channel');
        });

        Schema::create('staff_notification', function (Blueprint $table) {
            $table->string('notification_id')->primary();    // notif_...
            $table->string('operator_code');
            $table->string('source_module');                 // FUL-02 | WO-01 | BIL-04 | ...
            $table->string('source_task_id')->nullable();    // Camunda user-task id
            $table->string('source_process_instance')->nullable();
            $table->string('source_business_key')->nullable();
            $table->string('candidate_group');               // FOUNDATION_AUTH group code
            $table->string('template_code');
            $table->json('template_variables');
            $table->string('urgency');                       // low | medium | high
            $table->string('fallback_mode_override')->nullable();
            $table->text('deeplink_url')->nullable();
            $table->integer('ack_window_hours')->nullable();
            $table->integer('expected_recipients')->default(0);
            $table->string('status')->default('PROCESSING'); // PROCESSING | DISPATCHED | ACKNOWLEDGED | EXPIRED
            $table->string('expiry_reason')->nullable();     // NO_RECIPIENTS | ALL_CHANNELS_EXHAUSTED | ACK_WINDOW
            $table->string('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'status']);
            $table->index(['source_module', 'source_business_key']);
            $table->index(['operator_code', 'candidate_group', 'status']);
            $table->unique(['operator_code', 'idempotency_key'], 'staff_notif_idem');
        });

        Schema::create('staff_notification_delivery', function (Blueprint $table) {
            $table->string('delivery_id')->primary();        // deliv_...
            $table->string('notification_id');
            $table->string('operator_code');
            $table->string('recipient_user_id');
            $table->string('channel');
            $table->integer('channel_priority_idx')->default(0);
            $table->string('status')->default('PENDING');    // PENDING | DISPATCHED | ACKNOWLEDGED | FAILED | TERMINALLY_FAILED | SUPPRESSED
            $table->integer('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->json('provider_response')->nullable();
            $table->string('failure_reason')->nullable();    // BOUNCE | ADAPTER_NOT_REGISTERED | NO_BINDING_FOR_CHANNEL | IDENTITY_UNRESOLVABLE | ...
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index('notification_id');
            $table->index(['recipient_user_id', 'status']);
            $table->index(['status', 'next_retry_at'], 'staff_deliv_retry');
            $table->unique(['notification_id', 'recipient_user_id', 'channel'], 'staff_deliv_unique');
        });

        Schema::create('staff_group_membership', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('group_code');                    // kenya-l1-kyc, noc-team, ...
            $table->string('user_id');                       // FOUNDATION_AUTH user id (uid)
            $table->timestamps();

            $table->unique(['operator_code', 'group_code', 'user_id'], 'staff_group_member_unique');
            $table->index(['operator_code', 'group_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_group_membership');
        Schema::dropIfExists('staff_notification_delivery');
        Schema::dropIfExists('staff_notification');
        Schema::dropIfExists('staff_notification_user_channel_identity');
        Schema::dropIfExists('staff_notification_user_pref');
        Schema::dropIfExists('staff_notification_adapter_binding');
        Schema::dropIfExists('staff_notification_channel_config');
        Schema::dropIfExists('staff_notification_template');
    }
};
