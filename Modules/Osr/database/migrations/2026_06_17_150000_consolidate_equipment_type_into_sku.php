<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidate the duplicate equipment-model catalog. The equipment model is ONE entity;
 * OSR owns equipment (HLD §6.4), so OSR `equipment_sku` is the registry of record — it's
 * the one wired into stock, instances and procurement. Catalog's near-unused
 * `equipment_type` config-CRUD copy is removed; its one extra field (warranty_days) moves
 * onto equipment_sku, and existing rows are migrated, then the table is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('equipment_sku', function (Blueprint $table) {
            $table->unsignedInteger('warranty_days')->default(90)->after('deposit_amount');
        });

        if (Schema::hasTable('equipment_type')) {
            DB::statement(<<<'SQL'
                INSERT INTO equipment_sku (sku_id, operator_code, name, category, is_serialized, ownership_semantics, deposit_amount, warranty_days, active, created_at, updated_at)
                SELECT et.operator_code || '-' || et.code, et.operator_code, et.name, COALESCE(et.category, 'OTHER'),
                       et.serialized, 'RETURNABLE', et.default_deposit, et.warranty_days, true, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                FROM equipment_type et
                WHERE NOT EXISTS (SELECT 1 FROM equipment_sku s WHERE s.sku_id = et.operator_code || '-' || et.code)
            SQL);

            Schema::drop('equipment_type');
        }
    }

    public function down(): void
    {
        Schema::create('equipment_type', function (Blueprint $table) {
            $table->string('equipment_type_id')->primary();
            $table->string('operator_code')->index();
            $table->string('code');
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('default_deposit', 12, 2)->default(0);
            $table->unsignedInteger('warranty_days')->default(90);
            $table->boolean('serialized')->default(true);
            $table->timestamps();
            $table->unique(['operator_code', 'code']);
        });
        Schema::table('equipment_sku', fn (Blueprint $t) => $t->dropColumn('warranty_days'));
    }
};
