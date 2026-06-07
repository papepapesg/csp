<?php

namespace App\Foundation\Workflow;

use App\Foundation\Errors\DomainException;
use App\Foundation\Errors\ErrorCode;
use App\Foundation\Support\Context;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued worker that executes a workflow for one operation (native Camunda
 * driver). Retryable; failures mark the operation FAILED with the error code.
 */
class RunOperation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $operationId) {}

    public function handle(): void
    {
        $operation = Operation::query()->where('operation_id', $this->operationId)->first();

        if (! $operation || in_array($operation->status, [Operation::COMPLETED, Operation::CANCELLED], true)) {
            return;
        }

        if ($operation->correlation_id) {
            Context::setCorrelationId($operation->correlation_id);
        }
        Context::setOperatorCode($operation->operator_code);

        $workflowClass = $operation->workflow;
        if (! $workflowClass || ! class_exists($workflowClass)) {
            $operation->markFailed(ErrorCode::INTERNAL_ERROR, "Unknown workflow [{$workflowClass}].");

            return;
        }

        /** @var Workflow $workflow */
        $workflow = app($workflowClass);

        try {
            $operation->markRunning($workflow->key());
            $workflow->handle($operation);

            if ($operation->fresh()?->status === Operation::RUNNING) {
                $operation->markCompleted($operation->result ?? []);
            }
        } catch (DomainException $e) {
            $operation->markFailed($e->errorCode, $e->getMessage());
        } catch (\Throwable $e) {
            $operation->markFailed(ErrorCode::INTERNAL_ERROR, $e->getMessage());
            throw $e; // allow queue retry
        }
    }
}
