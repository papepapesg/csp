<?php

namespace Modules\Billing\Adjustments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-ADJ-01 approval audit step — one row per decision on a proposal.
 */
class AdjustmentApprovalStep extends Model
{
    // Decisions recorded on the timeline (approvals AND the non-approval events).
    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const REVISION_REQUESTED = 'REVISION_REQUESTED';

    public const LIMIT_OVERRIDDEN = 'LIMIT_OVERRIDDEN';

    /** decided_by handle when the engine auto-approves (no human actor). */
    public const SYSTEM_AUTO = 'SYSTEM:AUTO_APPROVE';

    protected $table = 'adjustment_approval_step';

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];
}
