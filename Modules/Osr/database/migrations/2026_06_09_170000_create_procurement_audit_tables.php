<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-02 Procurement + OSR-05 Inventory Audit. purchase_order (+ lines) tracks
 * buying stock from a supplier through to goods receipt (which posts an OSR-01
 * stock movement). stock_count_session (+ lines) drives a physical count and
 * computes per-SKU variance against system balances, posting correction movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order', function (Blueprint $table) {
            $table->string('po_id')->primary();                 // po_...
            $table->string('operator_code')->index();
            $table->string('supplier');
            $table->string('location_id')->nullable();          // receiving location
            $table->string('status')->default('DRAFT')->index(); // DRAFT|APPROVED|PARTIALLY_RECEIVED|RECEIVED|CANCELLED
            $table->decimal('total_value', 14, 2)->default(0);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_line', function (Blueprint $table) {
            $table->string('po_line_id')->primary();
            $table->string('po_id')->index();
            $table->string('sku_id');
            $table->unsignedInteger('quantity_ordered');
            $table->unsignedInteger('quantity_received')->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('po_id')->references('po_id')->on('purchase_order')->cascadeOnDelete();
        });

        Schema::create('stock_count_session', function (Blueprint $table) {
            $table->string('session_id')->primary();            // scs_...
            $table->string('operator_code')->index();
            $table->string('location_id')->index();
            $table->string('status')->default('OPEN')->index(); // OPEN|COUNTED|RECONCILED
            $table->unsignedInteger('variance_lines')->default(0);
            $table->string('created_by')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_line', function (Blueprint $table) {
            $table->string('count_line_id')->primary();
            $table->string('session_id')->index();
            $table->string('sku_id');
            $table->integer('system_qty')->default(0);
            $table->integer('counted_qty')->default(0);
            $table->integer('variance')->default(0);
            $table->timestamps();

            $table->foreign('session_id')->references('session_id')->on('stock_count_session')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_line');
        Schema::dropIfExists('stock_count_session');
        Schema::dropIfExists('purchase_order_line');
        Schema::dropIfExists('purchase_order');
    }
};
