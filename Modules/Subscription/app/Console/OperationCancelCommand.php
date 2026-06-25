<?php

namespace Modules\Subscription\Console;

use Illuminate\Console\Command;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Services\OperationFramework;

/**
 * Ops safe-correction: cancel one stuck in-flight subscription operation via the
 * EXISTING OperationFramework::cancel() (SUB-WF-FRAMEWORK-01 §8.2). The service
 * already guards terminal operations, cancels the workflow instance and reverts a
 * transient PENDING_* master flip to its prior status (R-SUB-WF-FW-3) — the same
 * compensation the operation-timeout sweep applies. No approval gate is involved;
 * cancelling is destructive, so --confirm is required.
 */
class OperationCancelCommand extends Command
{
    protected $signature = 'sophix:subscription:operation-cancel
        {operation : The operation_id to cancel}
        {--reason=OPS_MANUAL_CANCEL : Recorded as the cancel_reason_code}
        {--actor=cli-ops : Recorded as the cancel_actor_user_id}
        {--confirm : Required — cancelling an in-flight operation is destructive}';

    protected $description = 'Safe-correction: cancel one stuck in-flight subscription operation (wraps OperationFramework::cancel)';

    public function handle(OperationFramework $framework): int
    {
        $operationId = (string) $this->argument('operation');
        $operation = SubscriptionOperation::query()->where('operation_id', $operationId)->first();
        if (! $operation) {
            $this->error("No operation {$operationId}.");

            return self::FAILURE;
        }

        if (! $operation->isInFlight()) {
            $this->warn("Operation {$operationId} is already terminal (final_state={$operation->final_state}); nothing to do.");

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->error("Cancelling in-flight operation {$operationId} (kind={$operation->operation_kind}, state={$operation->current_state}) is destructive; re-run with --confirm.");

            return self::FAILURE;
        }

        $reason = (string) $this->option('reason');
        $actor = (string) $this->option('actor');

        $framework->cancel($operation, $reason, $actor);

        $this->info("operation-cancel: {$operationId} cancelled (reason={$reason}) by {$actor}.");
        $this->call('sophix:subscription:operation-show', ['subscription' => $operation->subscription_id]);

        return self::SUCCESS;
    }
}
