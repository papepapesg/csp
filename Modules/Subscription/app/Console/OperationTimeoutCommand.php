<?php

namespace Modules\Subscription\Console;

use Illuminate\Console\Command;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Models\SubscriptionOperationConfig;
use Modules\Workflow\Models\ProcessInstance;

/**
 * SUB-WF-FRAMEWORK-01 R-SUB-WF-FW-9/10 operation timeout sweep. An in-flight
 * operation older than its configured operation_timeout_seconds enters REVERTING
 * then ends FAILED with OPERATION_TIMEOUT; the workflow instance is cancelled and
 * any transient PENDING_* flip is reverted to the prior status.
 */
class OperationTimeoutCommand extends Command
{
    protected $signature = 'sophix:subscription:operation-timeouts';

    protected $description = 'Fail in-flight subscription operations past their timeout';

    public function handle(): int
    {
        $timedOut = 0;
        SubscriptionOperation::query()->whereNull('final_state')->whereNotNull('started_at')
            ->chunkById(200, function ($ops) use (&$timedOut) {
                foreach ($ops as $op) {
                    $timeout = SubscriptionOperationConfig::resolve($op->operator_code, $op->operation_kind)?->operation_timeout_seconds ?? 120;
                    if ($op->started_at->diffInSeconds(now()) < $timeout) {
                        continue;
                    }

                    $op->markState(SubscriptionOperation::REVERTING);
                    if ($op->bpmn_process_instance_id) {
                        ProcessInstance::query()->whereKey($op->bpmn_process_instance_id)
                            ->where('status', ProcessInstance::RUNNING)
                            ->update(['status' => ProcessInstance::FAILED, 'error_message' => 'OPERATION_TIMEOUT', 'ended_at' => now()]);
                    }
                    $sub = Subscription::query()->find($op->subscription_id);
                    if ($sub && str_starts_with((string) $sub->status_code, 'PENDING_') && $op->prior_subscription_status) {
                        $sub->update(['status_code' => $op->prior_subscription_status, 'last_status_changed_at' => now()]);
                    }
                    $op->markFailed('OPERATION_TIMEOUT', 'Operation exceeded its configured timeout.');
                    $timedOut++;
                }
            }, 'operation_id');

        $this->info("timed out {$timedOut} operation(s)");

        return self::SUCCESS;
    }
}
