<?php

namespace Modules\Fulfillment\Console;

use Illuminate\Console\Command;
use Modules\Fulfillment\Models\FulfillmentOrder;

/**
 * Ops review: a one-glance count of FUL-02 orders parked mid-journey that an
 * operator may need to nudge (read-only). The deposit/install/KYC/activation
 * waits are where orders stall waiting on an external signal.
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:fulfillment:ops-status {--operator= : Scope to one operator code (default: all)}';

    protected $description = 'Review: counts of fulfillment orders parked mid-journey (read-only)';

    public function handle(): int
    {
        $op = $this->option('operator');
        $scope = fn () => $op
            ? FulfillmentOrder::query()->where('operator_code', $op)
            : FulfillmentOrder::query();

        $rows = [
            ['Awaiting deposit payment', $scope()->where('status', FulfillmentOrder::AWAITING_PAYMENT)->count()],
            ['Awaiting install confirmation', $scope()->where('status', FulfillmentOrder::AWAITING_INSTALL)->count()],
            ['Awaiting KYC', $scope()->where('status', FulfillmentOrder::AWAITING_KYC)->count()],
            ['Activating', $scope()->where('status', FulfillmentOrder::ACTIVATING)->count()],
            ['Captured (journey not yet advanced)', $scope()->where('status', FulfillmentOrder::CAPTURED)->count()],
            ['Completed', $scope()->where('status', FulfillmentOrder::COMPLETED)->count()],
            ['Cancelled', $scope()->where('status', FulfillmentOrder::CANCELLED)->count()],
        ];

        $this->info('Fulfillment ops status'.($op ? " — operator {$op}" : ' — all operators'));
        $this->table(['Order state', 'Count'], $rows);
        $this->line('Inspect: sophix:fulfillment:order-show <order> · Nudge: sophix:fulfillment:order-fix <order> <action>');

        return self::SUCCESS;
    }
}
