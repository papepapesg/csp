<?php

namespace Modules\Osr\Database\Seeders;

use App\Foundation\Support\Context;
use Illuminate\Database\Seeder;
use Modules\Osr\Models\EquipmentSku;
use Modules\Osr\Models\StockBalance;
use Modules\Osr\Models\StockLocation;

/**
 * OSR-01 demo stock dataset. Seeds a warehouse and a contractor van location, a few
 * equipment SKUs and on-hand stock balances so the OSR / inventory backoffice surfaces
 * open with real numbers. Idempotent (updateOrCreate).
 */
class OsrDemoSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($op);

        // ── Equipment SKUs (PLM-CFG-06) ──────────────────────────────────────
        $skus = [
            ['WIK-ONT-HUAWEI-EG8145V5', 'Huawei ONT EG8145V5', 'ONT', true, 'RETURNABLE', 0],
            ['WIK-ROUTER-TPLINK-AX10', 'TP-Link Router AX10', 'ROUTER', true, 'RETURNABLE', 0],
            ['WIK-STB-IPTV-X1', 'IPTV Set-Top Box X1', 'STB', true, 'RENTED', 1500],
            ['WIK-CABLE-DROP-100M', 'Fiber Drop Cable 100m', 'CABLE', false, 'CONSUMABLE', 0],
            ['WIK-SPLITTER-1x8', 'Optical Splitter 1x8', 'SPLITTER', false, 'CONSUMABLE', 0],
        ];
        foreach ($skus as [$id, $name, $category, $serialized, $ownership, $deposit]) {
            EquipmentSku::query()->updateOrCreate(
                ['sku_id' => $id],
                ['operator_code' => $op, 'name' => $name, 'category' => $category,
                 'is_serialized' => $serialized, 'ownership_semantics' => $ownership,
                 'deposit_amount' => $deposit, 'active' => true],
            );
        }

        // ── Stock locations ──────────────────────────────────────────────────
        $warehouse = StockLocation::query()->updateOrCreate(
            ['location_id' => 'WIK-WAREHOUSE-MAIN'],
            ['operator_code' => $op, 'type' => 'WAREHOUSE', 'name' => 'Main Warehouse (Nairobi)', 'active' => true],
        );
        $van = StockLocation::query()->updateOrCreate(
            ['location_id' => 'WIK-VAN-DEMO-01'],
            ['operator_code' => $op, 'type' => 'CONTRACTOR_VAN', 'name' => 'Demo Field Van 01', 'active' => true],
        );

        // ── Stock balances per (location, sku) ───────────────────────────────
        // [location_id, sku_id, quantity]
        $balances = [
            [$warehouse->location_id, 'WIK-ONT-HUAWEI-EG8145V5', 240],
            [$warehouse->location_id, 'WIK-ROUTER-TPLINK-AX10', 180],
            [$warehouse->location_id, 'WIK-STB-IPTV-X1', 95],
            [$warehouse->location_id, 'WIK-CABLE-DROP-100M', 60],
            [$warehouse->location_id, 'WIK-SPLITTER-1x8', 120],
            [$van->location_id, 'WIK-ONT-HUAWEI-EG8145V5', 8],
            [$van->location_id, 'WIK-ROUTER-TPLINK-AX10', 6],
            [$van->location_id, 'WIK-CABLE-DROP-100M', 4],
        ];
        foreach ($balances as [$locationId, $skuId, $qty]) {
            StockBalance::query()->updateOrCreate(
                ['location_id' => $locationId, 'sku_id' => $skuId],
                ['operator_code' => $op, 'quantity' => $qty],
            );
        }
    }
}
