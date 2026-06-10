<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-01: first-class stock_reason_code catalog (operator-scoped, with direction
 * + approval semantics) and a WO bill-of-materials so a job's required SKUs are
 * auto-reserved when the work order is committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reason_code', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('code');                          // RECEIPT | ISSUE | TRANSFER_IN | TRANSFER_OUT | INSTALL | RETURN | ADJUST | …
            $table->string('description');
            $table->string('direction')->default('EITHER');  // IN | OUT | EITHER
            $table->boolean('requires_approval')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });

        // Job-type → required materials (auto-reserved on WO commit).
        Schema::create('wo_material_requirement', function (Blueprint $table) {
            $table->id();
            $table->string('operator_code');
            $table->string('job_type_code');                 // e.g. FTTH_INSTALL
            $table->string('sku_id');
            $table->decimal('quantity', 12, 2)->default(1);
            $table->timestamps();
            $table->unique(['operator_code', 'job_type_code', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wo_material_requirement');
        Schema::dropIfExists('stock_reason_code');
    }
};
