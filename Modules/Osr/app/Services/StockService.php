<?php

namespace Modules\Osr\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\StockBalance;
use Modules\Osr\Models\StockMovement;

/**
 * OSR-01 stock chain. Movements are append-only; balances are maintained as a
 * derived projection updated inside the movement transaction for fast reads.
 */
class StockService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * Record a signed stock movement and update the (location, sku) balance.
     *
     * @param  array<string,mixed>  $data  sku_id, location_id, quantity (signed), reason_code, reference?
     */
    public function move(array $data): StockMovement
    {
        return DB::transaction(function () use ($data) {
            $operator = $data['operator_code'] ?? Context::operatorCode();

            $movement = StockMovement::query()->create([
                'operator_code' => $operator,
                'sku_id' => $data['sku_id'],
                'location_id' => $data['location_id'],
                'quantity' => $data['quantity'],
                'reason_code' => $data['reason_code'],
                'reference' => $data['reference'] ?? null,
                'created_at' => now(),
            ]);

            $balance = StockBalance::query()->lockForUpdate()->firstOrNew([
                'location_id' => $data['location_id'],
                'sku_id' => $data['sku_id'],
            ]);
            $balance->operator_code = $operator;
            $balance->quantity = (float) ($balance->quantity ?? 0) + (float) $data['quantity'];
            $balance->save();

            $this->events->publish(new DomainEvent(
                type: OsrEvents::STOCK_MOVED,
                topic: OsrEvents::TOPIC,
                payload: [
                    'skuId' => $data['sku_id'],
                    'locationId' => $data['location_id'],
                    'quantity' => (string) $data['quantity'],
                    'balance' => (string) $balance->quantity,
                    'reasonCode' => $data['reason_code'],
                ],
                aggregateType: 'StockBalance',
                aggregateId: $balance->id,
            ));

            return $movement;
        });
    }
}
