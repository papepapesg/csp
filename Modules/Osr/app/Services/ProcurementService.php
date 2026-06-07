<?php

namespace Modules\Osr\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Models\PurchaseOrder;
use Modules\Osr\Models\PurchaseOrderLine;

/**
 * OSR-02 Procurement. Creates purchase orders, approves them, and receives goods —
 * a goods receipt posts an OSR-01 stock movement (+qty) at the receiving location
 * and advances the PO status. Stock authority stays with OSR-01.
 */
class ProcurementService
{
    public function __construct(private readonly StockService $stock) {}

    /** @param array<string,mixed> $data supplier, location_id, lines:[{sku_id,quantity,unit_cost}] */
    public function create(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {
            $po = PurchaseOrder::query()->create([
                'po_id' => Id::make('po'),
                'supplier' => $data['supplier'],
                'location_id' => $data['location_id'] ?? null,
                'status' => 'DRAFT',
                'created_by' => $data['created_by'] ?? null,
            ]);
            $total = 0.0;
            foreach ($data['lines'] as $line) {
                PurchaseOrderLine::query()->create([
                    'po_line_id' => Id::make('pol'), 'po_id' => $po->po_id,
                    'sku_id' => $line['sku_id'], 'quantity_ordered' => (int) $line['quantity'],
                    'unit_cost' => (float) ($line['unit_cost'] ?? 0),
                ]);
                $total += (int) $line['quantity'] * (float) ($line['unit_cost'] ?? 0);
            }
            $po->update(['total_value' => $total]);

            return $po->refresh();
        });
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        if ($po->status !== 'DRAFT') {
            throw DomainException::conflict('Only DRAFT purchase orders can be approved.');
        }
        $po->update(['status' => 'APPROVED']);

        return $po;
    }

    /** Receive goods: post stock movements and advance status. */
    public function receive(PurchaseOrder $po): PurchaseOrder
    {
        if (! in_array($po->status, ['APPROVED', 'PARTIALLY_RECEIVED'], true)) {
            throw DomainException::conflict('Purchase order must be APPROVED to receive.');
        }

        return DB::transaction(function () use ($po) {
            foreach (PurchaseOrderLine::query()->where('po_id', $po->po_id)->get() as $line) {
                $outstanding = $line->quantity_ordered - $line->quantity_received;
                if ($outstanding <= 0) {
                    continue;
                }
                $this->stock->move([
                    'operator_code' => $po->operator_code,
                    'sku_id' => $line->sku_id,
                    'location_id' => $po->location_id ?? 'WIK-WAREHOUSE-MAIN',
                    'quantity' => $outstanding,
                    'reason_code' => 'GOODS_RECEIPT',
                    'reference' => $po->po_id,
                ]);
                $line->update(['quantity_received' => $line->quantity_ordered]);
            }
            $po->update(['status' => 'RECEIVED']);

            return $po->refresh();
        });
    }
}
