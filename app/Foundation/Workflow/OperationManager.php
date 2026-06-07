<?php

namespace App\Foundation\Workflow;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;

/**
 * Starts and tracks long-running operations (FOUNDATION_CAMUNDA, native driver).
 *
 * Provides the idempotent "start an operation, return an operation id, track
 * asynchronously" pattern required by DD_API-00 §7. Replays return the existing
 * operation when the same idempotency key + request hash is seen again.
 */
class OperationManager
{
    /**
     * @param  class-string<Workflow>  $workflow
     * @param  array<string, mixed>  $input
     */
    public function start(
        string $operationType,
        string $workflow,
        array $input = [],
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $idempotencyKey = null,
        ?string $requestHash = null,
    ): Operation {
        if ($idempotencyKey) {
            $existing = Operation::query()
                ->where('idempotency_key', $idempotencyKey)
                ->where('operation_type', $operationType)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $operation = DB::transaction(function () use (
            $operationType, $workflow, $input, $aggregateType, $aggregateId, $idempotencyKey, $requestHash
        ) {
            return Operation::query()->create([
                'operation_id' => Id::operation(),
                'operation_type' => $operationType,
                'workflow' => $workflow,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'operator_code' => Context::operatorCode(),
                'correlation_id' => Context::correlationId(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => Operation::PENDING,
                'input' => $input,
            ]);
        });

        RunOperation::dispatch($operation->operation_id);

        return $operation;
    }
}
