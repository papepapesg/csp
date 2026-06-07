<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSR / equipment: SKU catalog (PLM-CFG-06), stock chain (OSR-01: locations,
 * movements, derived balances) and the serialized equipment instance registry
 * (OSR-INSTANCE-01). OSR owns equipment serial authority (HLD §6.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PLM-CFG-06 — Equipment SKU / type catalog.
        Schema::create('equipment_sku', function (Blueprint $table) {
            $table->string('sku_id')->primary();               // operator-scoped e.g. WIK-ONT-HUAWEI-EG8145V5
            $table->string('operator_code')->index();
            $table->string('name');
            $table->string('category');                        // ROUTER|ONT|STB|SPLITTER|CABLE|WALL_SOCKET|MOUNT_KIT|SMARTCARD
            $table->boolean('is_serialized')->default(false);
            $table->string('ownership_semantics')->default('RETURNABLE'); // RETURNABLE|CONSUMABLE|RENTED
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // OSR-01 — Stock location (warehouse or contractor van).
        Schema::create('stock_location', function (Blueprint $table) {
            $table->string('location_id')->primary();          // WIK-VAN-contractor_..., WIK-WAREHOUSE-MAIN
            $table->string('operator_code')->index();
            $table->string('type');                            // WAREHOUSE | CONTRACTOR_VAN
            $table->string('name');
            $table->string('contractor_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // OSR-01 — Stock movement (append-only); balances are derived projections.
        Schema::create('stock_movement', function (Blueprint $table) {
            $table->string('id')->primary();                   // mov_...
            $table->string('operator_code')->index();
            $table->string('sku_id')->index();
            $table->string('location_id')->index();
            $table->decimal('quantity', 14, 2);                // signed: + inbound, - outbound
            $table->string('reason_code');                     // RECEIPT|ISSUE|TRANSFER_IN|TRANSFER_OUT|INSTALL|RETURN|ADJUST
            $table->string('reference')->nullable();           // WO id, transfer id, etc.
            $table->timestamp('created_at')->useCurrent();
        });

        // OSR-01 — Derived balance per (location, sku) for fast reads.
        Schema::create('stock_balance', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('operator_code')->index();
            $table->string('location_id')->index();
            $table->string('sku_id')->index();
            $table->decimal('quantity', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['location_id', 'sku_id']);
        });

        // OSR-INSTANCE-01 — Serialized equipment instance master.
        Schema::create('equipment_instance', function (Blueprint $table) {
            $table->string('instance_id')->primary();          // eqi_...
            $table->string('operator_code')->index();
            $table->string('sku_id')->index();
            $table->string('serial')->index();
            $table->string('mac_address')->nullable();
            $table->string('state')->default('IN_MAIN_WAREHOUSE')->index(); // IN_MAIN_WAREHOUSE|IN_CONTRACTOR_STOCK|IN_FIELD_ACTIVE|RETURNED|FAULTY|RETIRED
            $table->string('location_id')->nullable()->index();
            $table->string('customer_id')->nullable();
            $table->string('subscription_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['operator_code', 'serial']);
        });

        // OSR-INSTANCE-01 — Append-only lifecycle event ledger.
        Schema::create('equipment_instance_lifecycle_event', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('instance_id')->index();
            $table->string('event_type');
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->string('location_id')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('instance_id')->references('instance_id')->on('equipment_instance')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_instance_lifecycle_event');
        Schema::dropIfExists('equipment_instance');
        Schema::dropIfExists('stock_balance');
        Schema::dropIfExists('stock_movement');
        Schema::dropIfExists('stock_location');
        Schema::dropIfExists('equipment_sku');
    }
};
