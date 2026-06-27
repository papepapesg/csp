<?php

namespace Modules\Osr\Procurement\Services;
use Modules\Osr\Services\EquipmentInstanceService;
use Modules\Osr\Services\StockService;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Errors\DomainException;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Procurement\Models\PurchaseOrder;
use Modules\Osr\Procurement\Models\PurchaseOrderLine;

/**
 * OSR-02 Procurement. Creates purchase orders, approves them, and receives goods —
 * a goods receipt posts an OSR-01 stock movement (+qty) at the receiving location
 * and advances the PO status. Stock authority stays with OSR-01.
 */
class ProcurementService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly EquipmentInstanceService $instances,
        private readonly ApprovalService $approvals,
    ) {}

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

    /**
     * R-OSR-02-04: PO approval is gated through the EM-CFG-04 engine (consistent with stock
     * write-offs / adjustments) rather than an ad-hoc status flip. With no approval policy
     * configured the request auto-approves and the PO goes straight to APPROVED; otherwise it
     * parks in PENDING_APPROVAL until decide() gathers the required approvals. OSR-02 stores
     * only the approval reference + final outcome (§5).
     */
    public function approve(PurchaseOrder $po, ?string $requestedBy = null): PurchaseOrder
    {
        if ($po->status !== PurchaseOrder::DRAFT) {
            throw DomainException::conflict('Only DRAFT purchase orders can be approved.');
        }

        $request = $this->approvals->request([
            'operator_code' => $po->operator_code,
            'entity_type' => 'PURCHASE_ORDER',
            'action' => 'PO_APPROVAL',
            'entity_ref' => $po->po_id,
            'amount' => (float) $po->total_value,
            'requested_by' => $requestedBy,
        ]);

        $po->update([
            'approval_request_id' => $request->request_id,
            'status' => $request->status === ApprovalRequest::PENDING ? PurchaseOrder::PENDING_APPROVAL : PurchaseOrder::APPROVED,
        ]);

        return $po->refresh();
    }

    /** Apply a PO approval decision once EM-CFG-04 reaches a final outcome (mirrors stock movements). */
    public function applyApprovalOutcome(ApprovalRequest $request): PurchaseOrder
    {
        if ($request->entity_type !== 'PURCHASE_ORDER') {
            throw DomainException::conflict('Approval is not a purchase-order request.');
        }
        $po = PurchaseOrder::query()->findOrFail($request->entity_ref);
        if ($po->status !== PurchaseOrder::PENDING_APPROVAL) {
            return $po; // already resolved — idempotent.
        }
        $po->update(['status' => $request->status === ApprovalRequest::APPROVED ? PurchaseOrder::APPROVED : PurchaseOrder::REJECTED]);

        return $po->refresh();
    }

    /**
     * Receive goods: post stock movements and advance status. For serialized SKUs, the
     * accepted serials are registered as OSR-INSTANCE records at the receiving location
     * (R-OSR-02-08), so per-serial traceability starts at the warehouse door.
     *
     * @param  array<string,array<int,string>>  $serialsBySku  sku_id => [serial, …] for serialized lines
     */
    public function receive(PurchaseOrder $po, array $serialsBySku = []): PurchaseOrder
    {
        if (! in_array($po->status, ['APPROVED', 'PARTIALLY_RECEIVED'], true)) {
            throw DomainException::conflict('Purchase order must be APPROVED to receive.');
        }

        return DB::transaction(function () use ($po, $serialsBySku) {
            $location = $po->location_id ?? 'WIK-WAREHOUSE-MAIN';
            foreach (PurchaseOrderLine::query()->where('po_id', $po->po_id)->get() as $line) {
                $outstanding = $line->quantity_ordered - $line->quantity_received;
                if ($outstanding <= 0) {
                    continue;
                }
                // R-OSR-02-07: accepted quantity becomes an OSR-01 stock movement.
                $this->stock->move([
                    'operator_code' => $po->operator_code,
                    'sku_id' => $line->sku_id,
                    'location_id' => $location,
                    'quantity' => $outstanding,
                    'reason_code' => 'GOODS_RECEIPT',
                    'reference' => $po->po_id,
                ]);
                $line->update(['quantity_received' => $line->quantity_ordered]);

                // R-OSR-02-08: serialized SKUs also create per-serial instance records.
                $serialized = DB::table('equipment_sku')->where('sku_id', $line->sku_id)->value('is_serialized');
                foreach ($serialsBySku[$line->sku_id] ?? [] as $serial) {
                    if ($serialized) {
                        $this->instances->register([
                            'operator_code' => $po->operator_code,
                            'sku_id' => $line->sku_id,
                            'serial' => $serial,
                            'location_id' => $location,
                        ]);
                    }
                }
            }
            $po->update(['status' => 'RECEIVED']);

            return $po->refresh();
        });
    }
}
