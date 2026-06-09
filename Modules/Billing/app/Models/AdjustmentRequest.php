<?php

namespace Modules\Billing\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public const PROPOSED = 'PROPOSED';

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CANCELLED_BY_PROPOSER = 'CANCELLED_BY_PROPOSER';

    public const APPLIED = 'APPLIED';

    public const APPLICATION_FAILED = 'APPLICATION_FAILED';

    public const CREDIT = 'CREDIT';

    public const DEBIT = 'DEBIT';

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
}
