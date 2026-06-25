<?php

namespace Modules\Fulfillment\Console;

use Illuminate\Console\Command;
use Modules\Fulfillment\Models\FulfillmentOrder;
use Modules\Workflow\Models\ProcessInstance;

/**
 * Ops review: show one FUL-02 order's state, its recorded step ledger and the
 * driving workflow instance (read-only) — so the desk can see exactly where a
 * journey is parked before touching anything.
 */
class OrderShowCommand extends Command
{
    protected $signature = 'sophix:fulfillment:order-show {order : The order_id (ford_...)}';

    protected $description = 'Review: show a fulfillment order, its steps and workflow instance (read-only)';

    public function handle(): int
    {
        $orderId = (string) $this->argument('order');
        $order = FulfillmentOrder::query()->with('steps')->find($orderId);
        if (! $order) {
            $this->warn("No fulfillment order {$orderId}.");

            return self::SUCCESS;
        }

        $this->table(['Field', 'Value'], [
            ['order_id', $order->order_id],
            ['operator_code', $order->operator_code],
            ['status', $order->status],
            ['current_step', (string) ($order->current_step ?? '—')],
            ['customer_id', $order->customer_id],
            ['account_id', $order->account_id],
            ['package_ref', $order->package_ref],
            ['billing_mode', $order->billing_mode],
            ['subscription_id', (string) ($order->subscription_id ?? '—')],
            ['work_order_id', (string) ($order->work_order_id ?? '—')],
            ['process_instance_id', (string) ($order->process_instance_id ?? '—')],
            ['payment_ref', (string) ($order->payment_ref ?? '—')],
            ['completed_at', (string) $order->completed_at],
        ]);

        $steps = $order->steps->map(fn ($s) => [
            $s->step,
            $s->status,
            (string) $s->completed_at,
        ])->all();
        $this->info('Step ledger:');
        $this->table(['step', 'status', 'completed_at'], $steps ?: [['—', '—', '—']]);

        $instance = $order->process_instance_id
            ? ProcessInstance::query()->find($order->process_instance_id)
            : null;
        if ($instance) {
            $this->info('Workflow instance:');
            $this->table(['Field', 'Value'], [
                ['instance_id', $instance->instance_id],
                ['process_key', $instance->process_key],
                ['status', $instance->status],
                ['error_message', (string) ($instance->error_message ?? '—')],
                ['started_at', (string) $instance->started_at],
                ['ended_at', (string) $instance->ended_at],
            ]);
            if (in_array($instance->status, [ProcessInstance::FAILED, ProcessInstance::SUSPENDED, ProcessInstance::CANCELLED], true)) {
                $this->warn("Workflow instance is {$instance->status} — the journey will not advance on its own.");
            }
        } else {
            $this->warn('No workflow instance linked to this order.');
        }

        return self::SUCCESS;
    }
}
