<?php

namespace Modules\Osr\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** OSR-01 stock reason-code catalog (the movement reasons, operator-scoped). */
class StockReasonCodeSeeder extends Seeder
{
    private const CODES = [
        ['code' => 'RECEIPT', 'description' => 'Goods received into stock', 'direction' => 'IN'],
        ['code' => 'ISSUE', 'description' => 'Stock issued out', 'direction' => 'OUT'],
        ['code' => 'TRANSFER_IN', 'description' => 'Transfer received', 'direction' => 'IN'],
        ['code' => 'TRANSFER_OUT', 'description' => 'Transfer dispatched', 'direction' => 'OUT'],
        ['code' => 'INSTALL', 'description' => 'Consumed on a customer install', 'direction' => 'OUT'],
        ['code' => 'RETURN', 'description' => 'Returned to stock', 'direction' => 'IN'],
        ['code' => 'ADJUST', 'description' => 'Inventory adjustment (count variance)', 'direction' => 'EITHER', 'requires_approval' => true],
        ['code' => 'WRITE_OFF', 'description' => 'Damaged/lost write-off', 'direction' => 'OUT', 'requires_approval' => true],
    ];

    public function run(): void
    {
        foreach (['WIK', 'WUG', 'WTZ', 'YASSN'] as $operator) {
            foreach (self::CODES as $c) {
                DB::table('stock_reason_code')->updateOrInsert(
                    ['operator_code' => $operator, 'code' => $c['code']],
                    $c + ['requires_approval' => $c['requires_approval'] ?? false, 'active' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }
}
