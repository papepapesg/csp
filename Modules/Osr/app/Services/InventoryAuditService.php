<?php

namespace Modules\Osr\Services;

use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Models\StockBalance;
use Modules\Osr\Models\StockCountLine;
use Modules\Osr\Models\StockCountSession;

/**
 * OSR-05 Inventory Audit. Opens a count session for a location, records counted
 * quantities per SKU against the system balance, computes variance, and on
 * reconcile posts correction movements so the system balance matches the physical
 * count. Variances are auditable.
 */
class InventoryAuditService
{
    public function __construct(private readonly StockService $stock) {}

    public function open(string $locationId, ?string $actor = null): StockCountSession
    {
        return StockCountSession::query()->create([
            'session_id' => Id::make('scs'),
            'location_id' => $locationId,
            'status' => 'OPEN',
            'created_by' => $actor,
        ]);
    }

    /**
     * Record counted quantities and compute variance vs system balance.
     *
     * @param  array<int,array{sku_id:string,counted_qty:int}>  $counts
     */
    public function count(StockCountSession $session, array $counts): StockCountSession
    {
        return DB::transaction(function () use ($session, $counts) {
            $varianceLines = 0;
            foreach ($counts as $c) {
                $systemQty = (int) (StockBalance::query()
                    ->where('location_id', $session->location_id)->where('sku_id', $c['sku_id'])->value('quantity') ?? 0);
                $variance = (int) $c['counted_qty'] - $systemQty;
                StockCountLine::query()->updateOrCreate(
                    ['session_id' => $session->session_id, 'sku_id' => $c['sku_id']],
                    ['count_line_id' => Id::make('scl'), 'system_qty' => $systemQty, 'counted_qty' => (int) $c['counted_qty'], 'variance' => $variance],
                );
                if ($variance !== 0) {
                    $varianceLines++;
                }
            }
            $session->update(['status' => 'COUNTED', 'variance_lines' => $varianceLines]);

            return $session->refresh();
        });
    }

    /** Reconcile: post correction movements so system balances match the count. */
    public function reconcile(StockCountSession $session): StockCountSession
    {
        // R-OSR-05-09: applying a resolution must be idempotent — only a COUNTED session
        // posts corrections; a re-issued reconcile on an already-RECONCILED session is a no-op
        // (otherwise the variance lines would be posted a second time and double-adjust stock).
        if ($session->status !== 'COUNTED') {
            return $session;
        }

        return DB::transaction(function () use ($session) {
            foreach (StockCountLine::query()->where('session_id', $session->session_id)->where('variance', '!=', 0)->get() as $line) {
                $this->stock->move([
                    'operator_code' => $session->operator_code,
                    'sku_id' => $line->sku_id,
                    'location_id' => $session->location_id,
                    'quantity' => $line->variance, // signed correction
                    'reason_code' => 'INVENTORY_AUDIT_ADJUSTMENT',
                    'reference' => $session->session_id,
                ]);
            }
            $session->update(['status' => 'RECONCILED', 'reconciled_at' => now()]);

            return $session->refresh();
        });
    }
}
