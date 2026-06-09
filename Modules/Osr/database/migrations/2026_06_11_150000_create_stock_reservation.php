<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR-01 stock reservation lifecycle (§1.5): committed work orders reserve stock so
 * the qty is visible-but-unavailable, then the reservation is consumed on install
 * (an INSTALL movement deducts on-hand) or released on WO cancellation. Adds the
 * reserved column to the balance projection and the reservation ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balance', function (Blueprint $table) {
            $table->decimal('qty_reserved', 14, 2)->default(0)->after('quantity'); // available = quantity - qty_reserved
        });

        Schema::create('stock_reservation', function (Blueprint $table) {
            $table->string('reservation_id')->primary();        // rsv_...
            $table->string('operator_code')->index();
            $table->string('sku_id')->index();
            $table->string('location_id')->index();
            $table->decimal('qty', 14, 2);
            $table->string('wo_id')->nullable()->index();       // the committed work order
            $table->string('reference')->nullable();
            $table->string('status')->default('ACTIVE')->index(); // ACTIVE | CONSUMED | RELEASED
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reservation');
        Schema::table('stock_balance', fn (Blueprint $t) => $t->dropColumn('qty_reserved'));
    }
};
