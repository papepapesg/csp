<?php

namespace Modules\Osr\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Facades\DB;
use Modules\Osr\Events\OsrEvents;
use Modules\Osr\Models\StockBalance;
use Modules\Osr\Models\StockMovement;
use Modules\Osr\Models\StockReservation;

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

            // OSR-01: when the operator governs a reason-code catalog, the movement
            // reason must be an ACTIVE catalog code (soft-enforced like other catalogs).
            $hasCatalog = DB::table('stock_reason_code')->where('operator_code', $operator)->where('active', true)->exists();
            if ($hasCatalog && ! DB::table('stock_reason_code')->where('operator_code', $operator)->where('code', $data['reason_code'])->where('active', true)->exists()) {
                throw DomainException::ruleRejected('UNKNOWN_STOCK_REASON', "Reason '{$data['reason_code']}' is not an ACTIVE stock reason code.");
            }

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

    /**
     * Two-tier transfer between locations: a TRANSFER_OUT at the source and a
     * TRANSFER_IN at the destination, atomically. The pair keeps both balances
     * correct and leaves an auditable in-transit trail via the reference.
     *
     * @return array{out: StockMovement, in: StockMovement}
     */
    public function transfer(string $skuId, string $fromLocationId, string $toLocationId, float $qty, ?string $reference = null): array
    {
        return DB::transaction(function () use ($skuId, $fromLocationId, $toLocationId, $qty, $reference) {
            $ref = $reference ?? ('xfer:'.\App\Foundation\Support\Id::make('xf'));
            $available = (float) (StockBalance::query()->where('location_id', $fromLocationId)->where('sku_id', $skuId)->value('quantity') ?? 0);
            if ($available + 0.0001 < $qty) {
                throw DomainException::ruleRejected('INSUFFICIENT_STOCK', "Only {$available} of {$skuId} at {$fromLocationId} to transfer.");
            }

            return [
                'out' => $this->move(['sku_id' => $skuId, 'location_id' => $fromLocationId, 'quantity' => -$qty, 'reason_code' => 'TRANSFER_OUT', 'reference' => $ref]),
                'in' => $this->move(['sku_id' => $skuId, 'location_id' => $toLocationId, 'quantity' => $qty, 'reason_code' => 'TRANSFER_IN', 'reference' => $ref]),
            ];
        });
    }

    /**
     * WO bill-of-materials: reserve each material a job type requires from the
     * given location (called when a WO is committed). Returns the reservations.
     *
     * @return array<int,StockReservation>
     */
    public function reserveForJob(string $jobTypeCode, string $locationId, string $woId): array
    {
        $required = DB::table('wo_material_requirement')
            ->where('operator_code', Context::operatorCode())
            ->where('job_type_code', $jobTypeCode)->get();

        $reservations = [];
        foreach ($required as $row) {
            $reservations[] = $this->reserve($row->sku_id, $locationId, (float) $row->quantity, $woId, 'bom:'.$jobTypeCode);
        }

        return $reservations;
    }

    /**
     * OSR-01 §1.5 reserve stock for a committed WO. Qty becomes reserved (counted
     * on-hand but not available for new commitments). Rejects when the available
     * qty (on_hand − reserved) cannot cover it.
     */
    public function reserve(string $skuId, string $locationId, float $qty, ?string $woId = null, ?string $reference = null): StockReservation
    {
        return DB::transaction(function () use ($skuId, $locationId, $qty, $woId, $reference) {
            $operator = Context::operatorCode();
            $balance = StockBalance::query()->lockForUpdate()->firstOrNew(['location_id' => $locationId, 'sku_id' => $skuId]);
            $available = (float) ($balance->quantity ?? 0) - (float) ($balance->qty_reserved ?? 0);
            if ($available + 0.0001 < $qty) {
                throw DomainException::ruleRejected('INSUFFICIENT_STOCK', "Only {$available} of {$skuId} available at {$locationId}.");
            }

            $balance->operator_code = $operator;
            $balance->qty_reserved = (float) ($balance->qty_reserved ?? 0) + $qty;
            $balance->save();

            $reservation = StockReservation::query()->create([
                'sku_id' => $skuId, 'location_id' => $locationId, 'qty' => $qty,
                'wo_id' => $woId, 'reference' => $reference, 'status' => StockReservation::ACTIVE,
            ]);
            $this->emitReservation(OsrEvents::STOCK_RESERVED, $reservation);

            return $reservation;
        });
    }

    /**
     * Consume the WO's active reservations on install: each posts an INSTALL movement
     * (deducting on-hand), drops the reserved qty, and marks the reservation CONSUMED.
     *
     * @return int reservations consumed
     */
    public function consumeReservation(string $woId): int
    {
        return DB::transaction(function () use ($woId) {
            $reservations = StockReservation::query()->where('wo_id', $woId)->where('status', StockReservation::ACTIVE)->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $this->releaseReservedQty($reservation);
                $this->move([
                    'operator_code' => $reservation->operator_code,
                    'sku_id' => $reservation->sku_id,
                    'location_id' => $reservation->location_id,
                    'quantity' => -1 * (float) $reservation->qty, // OUTBOUND: installed at customer
                    'reason_code' => 'INSTALL',
                    'reference' => $woId,
                ]);
                $reservation->update(['status' => StockReservation::CONSUMED, 'resolved_at' => now()]);
                $this->emitReservation(OsrEvents::STOCK_RESERVATION_CONSUMED, $reservation);
            }

            return $reservations->count();
        });
    }

    /** Release the WO's active reservations (e.g. WO cancelled) — frees the reserved qty. */
    public function releaseReservation(string $woId): int
    {
        return DB::transaction(function () use ($woId) {
            $reservations = StockReservation::query()->where('wo_id', $woId)->where('status', StockReservation::ACTIVE)->lockForUpdate()->get();
            foreach ($reservations as $reservation) {
                $this->releaseReservedQty($reservation);
                $reservation->update(['status' => StockReservation::RELEASED, 'resolved_at' => now()]);
                $this->emitReservation(OsrEvents::STOCK_RESERVATION_RELEASED, $reservation);
            }

            return $reservations->count();
        });
    }

    /**
     * Stock availability for a (sku, location): on-hand, reserved, and available.
     *
     * @return array{onHand:float, reserved:float, available:float}
     */
    public function availability(string $skuId, string $locationId): array
    {
        $balance = StockBalance::query()->where('location_id', $locationId)->where('sku_id', $skuId)->first();
        $onHand = (float) ($balance->quantity ?? 0);
        $reserved = (float) ($balance->qty_reserved ?? 0);

        return ['onHand' => $onHand, 'reserved' => $reserved, 'available' => $onHand - $reserved];
    }

    private function releaseReservedQty(StockReservation $reservation): void
    {
        $balance = StockBalance::query()->lockForUpdate()
            ->where('location_id', $reservation->location_id)->where('sku_id', $reservation->sku_id)->first();
        if ($balance) {
            $balance->qty_reserved = max((float) $balance->qty_reserved - (float) $reservation->qty, 0);
            $balance->save();
        }
    }

    private function emitReservation(string $type, StockReservation $reservation): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: OsrEvents::TOPIC,
            payload: ['reservationId' => $reservation->reservation_id, 'skuId' => $reservation->sku_id, 'locationId' => $reservation->location_id, 'qty' => (string) $reservation->qty, 'woId' => $reservation->wo_id, 'status' => $reservation->status],
            aggregateType: 'StockReservation',
            aggregateId: $reservation->reservation_id,
        ));
    }
}
