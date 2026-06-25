<?php

namespace Modules\Fulfillment\Console;

use Illuminate\Console\Command;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Fulfillment\Services\OrderCaptureService;

/**
 * Ops safe-correction: drive one FUL-02 order forward through the EXISTING
 * OrderCaptureService desk operations (the same signals behind the order API
 * endpoints — no approval gate is involved). These correlate the journey's
 * waiting messages; 'cancel' is destructive and requires --confirm. Wraps the
 * service methods directly, so it bypasses route permissions — break-glass
 * console use for ops with shell access.
 */
class OrderFixCommand extends Command
{
    protected $signature = 'sophix:fulfillment:order-fix
        {order : The order_id (ford_...)}
        {action : confirm-deposit|complete|cancel}
        {--reason= : Reason, for cancel}
        {--confirm : Required for destructive actions (cancel)}';

    protected $description = 'Safe-correction: drive a fulfillment order forward (confirm-deposit, complete, cancel)';

    private const DESTRUCTIVE = ['cancel'];

    public function handle(OrderCaptureService $orders): int
    {
        $orderId = (string) $this->argument('order');
        $action = (string) $this->argument('action');

        if (in_array($action, self::DESTRUCTIVE, true) && ! $this->option('confirm')) {
            $this->error("Action '{$action}' is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        $order = FulfillmentOrder::query()->find($orderId);
        if (! $order) {
            $this->error("No fulfillment order {$orderId}.");

            return self::FAILURE;
        }

        match ($action) {
            'confirm-deposit' => $orders->confirmDepositPaid($order),
            'complete' => $orders->complete($order),
            'cancel' => $orders->cancel($order, $this->option('reason')),
            default => throw new \InvalidArgumentException("Unknown action '{$action}'."),
        };

        $this->info("order-fix: '{$action}' applied to {$orderId}.");
        $this->call('sophix:fulfillment:order-show', ['order' => $orderId]);

        return self::SUCCESS;
    }
}
