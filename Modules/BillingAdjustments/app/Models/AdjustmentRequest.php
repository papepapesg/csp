<?php

namespace Modules\Billing\Adjustments\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Billing\Payments\Models\NoteApplication;

/**
 * BIL-02-ADJ-01 adjustment proposal. A governed request to issue a credit or
 * debit note against a customer: PROPOSED → PENDING_APPROVAL → APPROVED →
 * APPLIED, with REJECTED / CANCELLED_BY_PROPOSER / APPLICATION_FAILED side
 * exits. The note itself is an invoice row (type CREDIT_NOTE / DEBIT_NOTE).
 *
 * @property string $adjustment_id
 * @property string $status
 * @property string $direction
 * @property string $scope
 */
class AdjustmentRequest extends Model
{
    use HasPrefixedId;

    // Lifecycle statuses.
    public const PROPOSED = 'PROPOSED';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED_BY_PROPOSER = 'CANCELLED_BY_PROPOSER';

    public const APPLIED = 'APPLIED';

    public const APPLICATION_FAILED = 'APPLICATION_FAILED';

    /** Statuses still open for an approval decision. */
    public const OPEN_STATUSES = [self::PROPOSED, self::PENDING_APPROVAL];

    // Directions.
    public const CREDIT = 'CREDIT';

    public const DEBIT = 'DEBIT';

    // Scopes (R-ADJ-01-P-3).
    public const FULL = 'FULL';

    public const LINE = 'LINE';

    public const AMOUNT = 'AMOUNT';

    // Billing modes.
    public const POSTPAID = 'POSTPAID';

    public const PREPAID = 'PREPAID';

    /** EM-CFG-04 entity type of every adjustment approval gate. */
    public const ENTITY_TYPE = 'ADJUSTMENT';

    // Pre-authored approval process tiers (approval_definition actions the rules engine selects).
    public const PROCESS_AUTO = 'AUTO';

    public const PROCESS_SINGLE = 'SINGLE';

    public const PROCESS_DUAL = 'DUAL';

    /** failure_reason set while the proposal breaches the operator limits (lifted by /override-limit). */
    public const LIMIT_EXCEEDED = 'ADJUSTMENT_LIMIT_EXCEEDED';

    protected $table = 'adjustment_request';

    protected $primaryKey = 'adjustment_id';

    protected string $idPrefix = 'adj';

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'limit_overridden' => 'boolean',
        'applied_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'adjustment_id';
    }

    public function approvalSteps(): HasMany
    {
        return $this->hasMany(AdjustmentApprovalStep::class, 'adjustment_id', 'adjustment_id')->orderBy('step_no');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(NoteApplication::class, 'adjustment_request_id', 'adjustment_id');
    }

    /** Append one decision to the human-readable audit timeline (step_no auto-sequenced). */
    public function logStep(string $decision, ?string $decidedBy, ?string $comment = null): AdjustmentApprovalStep
    {
        return $this->approvalSteps()->create([
            'step_no' => $this->approvalSteps()->count() + 1,
            'decision' => $decision,
            'decided_by' => $decidedBy,
            'comment' => $comment,
            'decided_at' => now(),
        ]);
    }

    public function isLimitBlocked(): bool
    {
        return $this->failure_reason === self::LIMIT_EXCEEDED && ! $this->limit_overridden;
    }
}
