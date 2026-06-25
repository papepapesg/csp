<?php

namespace Modules\Osr\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Osr\Models\StockReservation;
use Modules\Osr\Services\StockService;

/**
 * Ops review: show the live stock picture for one (sku, location) — on-hand,
 * reserved and available (via StockService::availability), plus the ACTIVE
 * reservations holding that stock, so support can see why availability is low
 * before touching anything (read-only).
 */
class StockShowCommand extends Command
{
    protected $signature = 'sophix:stock:show
        {sku : The sku_id}
        {location : The location_id}
        {--operator= : Operator code for reservation scoping (default: configured default)}';

    protected $description = 'Review: show on-hand/reserved/available and active reservations for a sku at a location (read-only)';

    public function handle(StockService $stock): int
    {
        $skuId = (string) $this->argument('sku');
        $locationId = (string) $this->argument('location');
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);

        $a = $stock->availability($skuId, $locationId);
        $this->info("Stock for {$skuId} @ {$locationId}");
        $this->table(['Field', 'Value'], [
            ['on_hand', $a['onHand']],
            ['reserved', $a['reserved']],
            ['available', $a['available']],
        ]);

        $reservations = StockReservation::query()
            ->where('operator_code', $operator)
            ->where('sku_id', $skuId)->where('location_id', $locationId)
            ->where('status', StockReservation::ACTIVE)
            ->orderBy('expires_at')->get();

        if ($reservations->isEmpty()) {
            $this->line('No ACTIVE reservations.');

            return self::SUCCESS;
        }

        $this->table(
            ['reservation_id', 'qty', 'wo_id', 'reference', 'expires_at'],
            $reservations->map(fn (StockReservation $r) => [
                $r->reservation_id,
                $r->qty,
                $r->wo_id ?? '—',
                $r->reference ?? '—',
                (string) $r->expires_at,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
