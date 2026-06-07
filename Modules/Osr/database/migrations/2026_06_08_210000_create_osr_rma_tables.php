<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-RMA-01 Equipment Swap & RMA. The swap-request is the workflow root for every
 * flow where a serialized device moves OUT of a customer's premises (HFC/GPON swap,
 * pickup at termination, upgrade). The framework owns the shared schema; per-flow
 * specifics live in flow_payload (JSONB). vendor_rma_stub records outbound handoff
 * of defective units to a vendor (v1.0 STUB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_swap_request', function (Blueprint $table) {
            $table->string('swap_id')->primary();               // swp_...
            $table->string('operator_code')->index();
            $table->string('kind');                             // SWAP_HFC | SWAP_GPON | EQP | EQU
            $table->string('source_instance_id')->nullable()->index();
            $table->string('target_instance_id')->nullable()->index();
            $table->string('subscription_id')->nullable()->index();
            $table->string('customer_id')->nullable();
            $table->string('homepass_id')->nullable();
            $table->string('recovery_contractor_id')->nullable()->index(); // contractor who physically retrieves
            $table->string('status')->default('CREATED')->index();
            $table->boolean('chargeable')->default(false);
            $table->string('charge_code')->nullable();
            $table->decimal('charge_amount', 12, 2)->nullable();
            $table->string('failure_code')->nullable();
            $table->json('flow_payload')->nullable();           // per-flow specifics (slot metadata, etc.)
            $table->string('work_order_id')->nullable()->index();
            $table->string('slot_commitment_id')->nullable();
            $table->string('process_instance_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('vendor_rma_stub', function (Blueprint $table) {
            $table->string('id')->primary();                    // vrma_...
            $table->string('operator_code')->index();
            $table->string('swap_id')->index();
            $table->string('source_instance_id')->nullable();
            $table->string('vendor_ref')->nullable();
            $table->string('batch_ref')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_rma_stub');
        Schema::dropIfExists('equipment_swap_request');
    }
};
