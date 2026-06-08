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
    // SUB-WF-FRAMEWORK-01 §5 current_state vocabulary (narrates the workflow).
    public const INITIATED = 'INITIATED';

    public const VALIDATING = 'VALIDATING';

    public const PENDING_STATE_FLIP = 'PENDING_STATE_FLIP';

    public const BILLING_CALL = 'BILLING_CALL';

    public const AWAITING_PAYMENT = 'AWAITING_PAYMENT';

    public const FULFILLMENT_CALL = 'FULFILLMENT_CALL';

    public const AWAITING_FULFILLMENT_RESPONSE = 'AWAITING_FULFILLMENT_RESPONSE';

    public const AWAITING_USER_TASK = 'AWAITING_USER_TASK';

    public const COMMITTING_FINAL_STATE = 'COMMITTING_FINAL_STATE';

    public const EMITTING_EVENT = 'EMITTING_EVENT';

    public const REVERTING = 'REVERTING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    /** @deprecated framework now narrates via the §5 vocabulary; kept for back-compat. */
    public const PENDING = 'PENDING';

    /** @deprecated use the §5 vocabulary. */
    public const RUNNING = 'RUNNING';

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

    /** Whether this operation is still in-flight (final_state not yet set). */
    public function isInFlight(): bool
    {
        return $this->final_state === null;
    }

    /** Narrate the framework workflow state (SUB-WF-FRAMEWORK-01 §5). */
    public function markState(string $state): void
    {
        $this->update(['current_state' => $state]);
    }

    /** Narrate by operation id from inside a workflow step (best-effort). */
    public static function narrate(?string $operationId, string $state): void
    {
        if ($operationId) {
            static::query()->whereKey($operationId)->whereNull('final_state')->update(['current_state' => $state]);
        }
    }

    public function markRunning(): void
    {
        $this->update(['current_state' => self::VALIDATING, 'started_at' => $this->started_at ?? now()]);
    }

    public function markCancelled(string $reasonCode, ?string $actorId = null): void
    {
        $this->update([
            'current_state' => self::CANCELLED,
            'final_state' => self::CANCELLED,
            'cancel_reason_code' => $reasonCode,
            'cancel_actor_user_id' => $actorId,
            'completed_at' => now(),
        ]);
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
            'final_state' => self::FAILED,
            'failure_reason_code' => $code,
            'failure_reason_detail' => $detail,
            'completed_at' => now(),
        ]);
    }
}
