<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FA-01/02/03 unified field-audit capability (one capability, audit_type-driven, per the DD MVP
 * baseline). The existing flat `field_audit` is the lightweight no-WO path; this adds the DD's
 * campaign → task → expected-item → observation → discrepancy model that compares the OSR
 * snapshot (expected) against field evidence (observed) and routes typed discrepancies to
 * ticket / RMA-recovery / OSR-correction / write-off (risky routes gated by EM-CFG-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_audit_campaign', function (Blueprint $table) {
            $table->string('campaign_id')->primary();            // fac_...
            $table->string('operator_code')->index();
            $table->string('audit_type');                        // EQUIPMENT | NETWORK | KYC
            $table->string('campaign_type');                     // CUSTOMER_EQUIPMENT_VERIFY | POST_SWAP_VERIFY | CONTRACTOR_QUALITY | AREA_INVESTIGATION
            $table->string('area_code')->nullable();
            $table->string('technology_family')->nullable();
            $table->string('status')->default('DRAFT');          // DRAFT|SCHEDULED|IN_PROGRESS|RECONCILING|CLOSED|CANCELLED
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->string('created_by_user_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['operator_code', 'status']);
            $table->index(['operator_code', 'area_code', 'status']);
        });

        Schema::create('field_audit_task', function (Blueprint $table) {
            $table->string('audit_task_id')->primary();          // fat_...
            $table->string('operator_code')->index();
            $table->string('campaign_id')->nullable()->index();
            $table->string('audit_type');                        // EQUIPMENT | NETWORK | KYC
            $table->string('task_type');                         // CUSTOMER_PREMISES | FIELD_SITE | POST_SWAP | INVESTIGATION
            $table->string('customer_id')->nullable()->index();
            $table->string('account_id')->nullable();
            $table->string('subscription_id')->nullable()->index();
            $table->string('homepass_id')->nullable();
            $table->string('wo_id')->nullable()->index();
            $table->string('assigned_to_user_id')->nullable()->index();
            $table->string('assigned_team_id')->nullable();
            $table->string('status')->default('CREATED');        // CREATED|ASSIGNED|IN_PROGRESS|SUBMITTED|DISCREPANCY_OPEN|CLOSED|CANCELLED
            $table->string('source_event_ref')->nullable();      // idempotency
            $table->timestamp('due_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['operator_code', 'source_event_ref'], 'fat_idem');
            $table->index(['operator_code', 'status']);
        });

        Schema::create('field_audit_expected_item', function (Blueprint $table) {
            $table->string('expected_item_id')->primary();       // fae_...
            $table->string('audit_task_id')->index();
            $table->string('operator_code')->index();
            $table->string('equipment_instance_id')->nullable();
            $table->string('sku_id')->nullable();
            $table->string('serial_number')->nullable()->index();
            $table->string('expected_location_type')->nullable(); // CUSTOMER_PREMISES | HOMEPASS | FIELD_SITE
            $table->string('expected_location_ref')->nullable();
            $table->string('expected_condition')->nullable();     // INSTALLED_WORKING | ...
            $table->json('source_snapshot_json')->nullable();
            $table->timestamps();
        });

        Schema::create('field_audit_observation', function (Blueprint $table) {
            $table->string('observation_id')->primary();         // fao_...
            $table->string('operator_code')->index();
            $table->string('audit_task_id')->index();
            $table->string('expected_item_id')->nullable();
            $table->string('observed_equipment_instance_id')->nullable();
            $table->string('observed_sku_id')->nullable();
            $table->string('observed_serial_number')->nullable()->index();
            $table->string('presence_status')->nullable();        // PRESENT | MISSING | FOUND_EXTRA | NOT_ACCESSIBLE
            $table->string('condition_status')->nullable();       // WORKING | DAMAGED | TAMPERED | UNKNOWN
            $table->string('observed_location_ref')->nullable();
            $table->json('photo_file_ids_json')->nullable();
            $table->decimal('gps_latitude', 10, 6)->nullable();
            $table->decimal('gps_longitude', 10, 6)->nullable();
            $table->text('notes')->nullable();
            $table->string('captured_by_user_id')->nullable();
            $table->timestamp('captured_at');
            $table->string('offline_client_ref')->nullable();     // mobile offline idempotency
            $table->timestamps();

            $table->unique(['operator_code', 'offline_client_ref'], 'fao_offline_idem');
        });

        Schema::create('field_audit_discrepancy', function (Blueprint $table) {
            $table->string('discrepancy_id')->primary();         // fad_...
            $table->string('operator_code')->index();
            $table->string('audit_task_id')->index();
            $table->string('expected_item_id')->nullable();
            $table->string('observation_id')->nullable();
            $table->string('discrepancy_type');                  // MISSING | WRONG_SERIAL | FOUND_EXTRA | DAMAGED | WRONG_LOCATION | NOT_ACCESSIBLE
            $table->string('severity')->default('LOW');          // LOW | MEDIUM | HIGH | CRITICAL
            $table->string('status')->default('OPEN');           // OPEN|ROUTED|PENDING_APPROVAL|ACTION_CREATED|RESOLVED|REJECTED|CLOSED
            $table->string('route_action')->nullable();          // CREATE_TICKET | CREATE_RMA_RECOVERY | REQUEST_OSR_CORRECTION | REQUEST_WRITE_OFF | NO_ACTION
            $table->string('routed_ref_type')->nullable();
            $table->string('routed_ref_id')->nullable();
            $table->string('approval_request_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['audit_task_id', 'status']);
            $table->index(['discrepancy_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_audit_discrepancy');
        Schema::dropIfExists('field_audit_observation');
        Schema::dropIfExists('field_audit_expected_item');
        Schema::dropIfExists('field_audit_task');
        Schema::dropIfExists('field_audit_campaign');
    }
};
