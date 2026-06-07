<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FA-01/02/03 Field Audits — equipment, network and customer-KYC field audits
 * share one lifecycle: scheduled -> in field (findings + photos captured) ->
 * severity-assessed (rules.field_audit.<kind>.severity) -> routed to approval when
 * severe -> closed. The kind drives the target linkage + the rule package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_audit', function (Blueprint $table) {
            $table->string('audit_id')->primary();              // fa_...
            $table->string('operator_code')->index();
            $table->string('kind');                             // EQUIPMENT | NETWORK | KYC
            $table->string('target_type')->nullable();          // EquipmentInstance | NetworkNode | Customer
            $table->string('target_ref')->nullable()->index();
            $table->string('status')->default('SCHEDULED')->index(); // SCHEDULED|IN_FIELD|FINDINGS_SUBMITTED|UNDER_REVIEW|CLOSED
            $table->string('severity')->nullable();             // OK|LOW|MEDIUM|HIGH|CRITICAL
            $table->json('findings')->nullable();
            $table->json('photo_file_ids')->nullable();
            $table->string('approval_request_id')->nullable();
            $table->string('assigned_to')->nullable();
            $table->string('outcome')->nullable();              // VERIFIED|DISCREPANCY|FRAUD_SUSPECTED|...
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_audit');
    }
};
