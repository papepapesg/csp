<?php

namespace Modules\Osr\Console;

use App\Foundation\Support\Context;
use Illuminate\Console\Command;
use Modules\Osr\Services\StockService;

/** OSR-01 R-OSR-SC-7 reservation-expiry sweep — auto-releases reservations past their window. */
class ReservationExpiryCommand extends Command
{
    protected $signature = 'sophix:stock:expire-reservations {--operator=}';

    protected $description = 'Auto-release ACTIVE stock reservations past expires_at (R-OSR-SC-7)';

    public function handle(StockService $stock): int
    {
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($operator);
        $this->info('expired '.$stock->expireReservations($operator).' reservation(s)');

        return self::SUCCESS;
    }
}
