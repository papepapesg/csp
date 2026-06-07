<?php

namespace Modules\Subscription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SUB-WF-FRAMEWORK operation ledger row. Owns idempotency, concurrency and
 * workflow correlation for one subscription operation.
 *
 * @property string $operation_id
 * @property string $current_state
 */
class SubscriptionOperation extends Model
{
    public const PENDING = 'PENDING';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'subscription_operation';

    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'input' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'operation_id';
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id', 'subscription_id');
    }

    public function markRunning(): void
    {
        $this->update(['current_state' => self::RUNNING, 'started_at' => $this->started_at ?? now()]);
    }

    public function markCompleted(string $finalState): void
    {
        $this->update([
            'current_state' => self::COMPLETED,
            'final_state' => $finalState,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? (int) now()->diffInMilliseconds($this->started_at) : null,
        ]);
    }

    public function markFailed(string $code, string $detail): void
    {
        $this->update([
            'current_state' => self::FAILED,
            'failure_reason_code' => $code,
            'failure_reason_detail' => $detail,
            'completed_at' => now(),
        ]);
    }
}
