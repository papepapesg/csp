<?php

namespace App\Foundation\Workflow;

use Illuminate\Database\Eloquent\Model;

/**
 * Long-running operation record (FOUNDATION_CAMUNDA, native driver).
 *
 * @property string $operation_id
 * @property string $operation_type
 * @property string $status
 * @property array|null $input
 * @property array|null $result
 */
class Operation extends Model
{
    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'domain_operations';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'operation_id';
    }

    public function markRunning(?string $step = null): void
    {
        $this->update([
            'status' => self::RUNNING,
            'current_step' => $step,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markCompleted(array $result = []): void
    {
        $this->update([
            'status' => self::COMPLETED,
            'result' => $result,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $errorCode, string $message): void
    {
        $this->update([
            'status' => self::FAILED,
            'error_code' => $errorCode,
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }
}
