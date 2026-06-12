<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOT-01 Notification Service — the authoritative seven-table model from the DD.
 *
 *   template                          unified template (PDF / EMAIL_* / SMS_TEXT / future), versioned per
 *                                     (operator, format, purpose, locale)
 *   notification_routing_rule         (operator, event_type) -> ordered {channel, purpose, urgency, category}
 *   channel_operator_config           per-operator per-channel adapter + sender + credentials reference
 *   customer_notification_preference  per-customer opt-in / locale / time-window / email_status
 *   notification_log                  aggregate row per notification event (append-only, P10Y)
 *   notification_delivery_attempt     one row per channel send attempt (full retry history)
 *   render_failure_queue              renders that failed (template missing, engine error) for admin recovery
 *
 * These coexist with the pre-existing simplified `notification` / `notification_template`
 * tables (the imperative send + studio) and ICN-01 `internal_message`; the DD model is the
 * event-driven routing/dispatch/audit core.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- template (unified, format-decomposed, versioned) ----
        Schema::create('template', function (Blueprint $table) {
            $table->string('id')->primary();                    // tpl_...
            $table->string('operator_code')->index();
            $table->string('template_format');                  // PDF | EMAIL_HTML | EMAIL_TEXT | EMAIL_SUBJECT | SMS_TEXT | ...
            $table->string('template_purpose_code');            // INVOICE_CYCLE_POSTPAID, PTP_CONFIRMATION, ...
            $table->string('locale')->default('en');
            $table->integer('version')->default(1);
            $table->string('status')->default('DRAFT');         // ACTIVE | DRAFT | ARCHIVED | DISABLED
            $table->string('engine_type')->default('HANDLEBARS'); // HANDLEBARS | MUSTACHE | THYMELEAF | HTML_TO_PDF | ...
            $table->longText('template_payload');               // the template text/source
            $table->json('placeholder_schema')->nullable();     // declared placeholders (admin validation)
            $table->json('sample_data')->nullable();            // preview data
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'template_format', 'template_purpose_code', 'locale', 'version'], 'template_unique_version');
            $table->index(['operator_code', 'template_format', 'template_purpose_code', 'locale', 'status', 'version'], 'template_lookup');
        });

        // ---- notification_routing_rule ----
        Schema::create('notification_routing_rule', function (Blueprint $table) {
            $table->string('id')->primary();                    // nrr_...
            $table->string('operator_code');
            $table->string('event_type');                       // InvoiceIssued, PtpRegistered, ...
            $table->string('channel');                          // EMAIL | SMS | ...
            $table->string('template_purpose_code');
            $table->integer('priority')->default(1);            // lower tried first
            $table->string('urgency')->default('NORMAL');       // URGENT | NORMAL (urgent bypasses time-window)
            $table->string('category')->default('TRANSACTIONAL'); // TRANSACTIONAL | MARKETING (controls opt-out)
            $table->json('conditions')->nullable();             // optional payload-based conditions
            $table->boolean('enabled')->default(true);          // admin can pause (O-6)
            $table->boolean('needs_pdf')->default(false);       // channel attaches/links the rendered PDF
            $table->timestamps();

            $table->index(['operator_code', 'event_type', 'enabled', 'priority'], 'routing_lookup');
        });

        // ---- channel_operator_config ----
        Schema::create('channel_operator_config', function (Blueprint $table) {
            $table->string('id')->primary();                    // coc_...
            $table->string('operator_code');
            $table->string('channel');                          // EMAIL | SMS
            $table->string('adapter_implementation');           // smtp.default, sms.africastalking, ...
            $table->string('sender_identifier');                // From: address / SMS sender ID
            $table->string('credentials_ref')->nullable();      // secret-store reference (never inline)
            $table->json('additional_config')->nullable();      // adapter-specific (attach_pdf, max parts, ...)
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['operator_code', 'channel'], 'channel_config_unique');
            $table->index(['operator_code', 'enabled']);
        });

        // ---- customer_notification_preference ----
        Schema::create('customer_notification_preference', function (Blueprint $table) {
            $table->string('id')->primary();                    // cnp_...
            $table->string('customer_id')->unique();
            $table->string('operator_code')->index();
            $table->boolean('email_opt_in')->default(true);     // MARKETING email (transactional always sent)
            $table->boolean('sms_opt_in')->default(true);       // MARKETING sms (transactional always sent)
            $table->string('locale')->default('en');
            $table->time('preferred_time_window_start')->nullable();
            $table->time('preferred_time_window_end')->nullable();
            $table->string('email_status')->default('VALID');   // VALID | SOFT_BOUNCED | INVALID
            $table->timestamps();
        });

        // ---- notification_log (aggregate, append-only) ----
        Schema::create('notification_log', function (Blueprint $table) {
            $table->string('id')->primary();                    // ntf_...
            $table->string('operator_code');
            $table->string('customer_id')->nullable();
            $table->string('event_type');
            $table->string('source_event_id')->nullable();
            $table->string('source_entity_id')->nullable();
            $table->json('channels_attempted');                 // array of channels actually tried
            $table->string('final_status');                     // DISPATCHED | PARTIALLY_DISPATCHED | SUPPRESSED | ESCALATED | UNDELIVERABLE
            $table->timestamp('dispatched_at');
            $table->string('manual_resend_by')->nullable();
            $table->string('original_notification_id')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'customer_id', 'dispatched_at'], 'log_by_customer');
            $table->index(['event_type', 'dispatched_at'], 'log_by_event');
            $table->index('source_entity_id');
        });

        // ---- notification_delivery_attempt (per channel attempt) ----
        Schema::create('notification_delivery_attempt', function (Blueprint $table) {
            $table->string('id')->primary();                    // att_...
            $table->string('notification_id')->index();         // FK -> notification_log
            $table->string('operator_code')->index();
            $table->string('channel');
            $table->string('template_id')->nullable();
            $table->string('recipient');
            $table->integer('attempt_number')->default(1);
            $table->string('status');                           // SENT | FAILED | PENDING_RETRY | ESCALATED
            $table->string('failure_category')->nullable();     // TRANSIENT | PERMANENT_RECIPIENT | PERMANENT_TEMPLATE | PERMANENT_BUSINESS_RULE
            $table->text('failure_detail')->nullable();
            $table->json('channel_response')->nullable();       // raw response (truncated to 4KB)
            $table->string('external_reference')->nullable();   // channel message id / DLR ref
            $table->json('dispatch_context')->nullable();       // resolved dispatch needed to retry
            $table->timestamp('attempted_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['notification_id', 'attempt_number']);
            $table->index(['status', 'next_attempt_at'], 'attempt_retry_scan');
        });

        // ---- render_failure_queue ----
        Schema::create('render_failure_queue', function (Blueprint $table) {
            $table->string('id')->primary();                    // rfq_...
            $table->string('event_type');
            $table->string('source_entity_id');
            $table->string('operator_code')->index();
            $table->string('failure_reason');                   // TEMPLATE_NOT_FOUND | RENDER_ENGINE_ERROR | MALFORMED_PAYLOAD
            $table->text('failure_detail')->nullable();
            $table->integer('attempt_count')->default(1);
            $table->timestamp('last_attempt_at');
            $table->timestamp('next_attempt_at')->nullable();
            $table->string('status')->default('PENDING_RETRY');  // PENDING_RETRY | GAVE_UP_AUTO | RESOLVED
            $table->json('original_event_payload');
            $table->string('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at'], 'rfq_scan');
            $table->index(['operator_code', 'status'], 'rfq_admin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_failure_queue');
        Schema::dropIfExists('notification_delivery_attempt');
        Schema::dropIfExists('notification_log');
        Schema::dropIfExists('customer_notification_preference');
        Schema::dropIfExists('channel_operator_config');
        Schema::dropIfExists('notification_routing_rule');
        Schema::dropIfExists('template');
    }
};
