<?php

namespace Modules\Subscription\Console;

use Illuminate\Console\Command;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;

/**
 * Ops review: show one subscription's master state, its in-flight operations and
 * its applied restrictions (read-only) — so support can see why a subscription is
 * (or isn't) moving before touching anything.
 */
class OperationShowCommand extends Command
{
    protected $signature = 'sophix:subscription:operation-show {subscription : The subscription_id}';

    protected $description = 'Review: show a subscription\'s state, in-flight operations and restrictions (read-only)';

    public function handle(): int
    {
        $subscriptionId = (string) $this->argument('subscription');
        $sub = Subscription::query()->where('subscription_id', $subscriptionId)->first();
        if (! $sub) {
            $this->warn("No subscription {$subscriptionId}.");

            return self::SUCCESS;
        }

        $this->info("Subscription {$sub->subscription_id}");
        $this->table(['Field', 'Value'], [
            ['operator_code', $sub->operator_code],
            ['status_code', $sub->status_code],
            ['customer_id', $sub->customer_id],
            ['account_id', $sub->account_id],
            ['last_status_changed_at', (string) $sub->last_status_changed_at],
        ]);

        if (str_starts_with((string) $sub->status_code, 'PENDING_')) {
            $this->warn("status_code {$sub->status_code} is a transient PENDING_* flip — an operation is (or should be) in flight.");
        }

        $inFlight = SubscriptionOperation::query()
            ->where('subscription_id', $sub->subscription_id)
            ->whereNull('final_state')
            ->orderBy('started_at')
            ->get();

        if ($inFlight->isEmpty()) {
            $this->line('No in-flight operations.');
        } else {
            $this->info('In-flight operations:');
            $this->table(
                ['operation_id', 'kind', 'current_state', 'started_at', 'process_instance'],
                $inFlight->map(fn (SubscriptionOperation $o) => [
                    $o->operation_id,
                    $o->operation_kind,
                    $o->current_state,
                    (string) $o->started_at,
                    $o->bpmn_process_instance_id ?? '—',
                ])->all(),
            );
        }

        $restrictions = $sub->active_restrictions ?? [];
        if ($restrictions === []) {
            $this->line('No active restrictions.');
        } else {
            $this->info('Active restrictions:');
            $this->table(
                ['restrictionCode', 'dunningMarker', 'activationTrigger', 'addedAt'],
                array_map(fn (array $r) => [
                    $r['restrictionCode'] ?? '?',
                    ($r['dunningMarker'] ?? false) ? 'yes' : 'no',
                    $r['activationTrigger'] ?? '—',
                    $r['addedAt'] ?? '—',
                ], $restrictions),
            );
        }

        return self::SUCCESS;
    }
}
