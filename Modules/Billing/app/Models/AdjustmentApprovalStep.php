<?php

namespace Modules\Billing\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-ADJ-01 approval audit step — one row per decision on a proposal.
 */
class AdjustmentApprovalStep extends Model
{
    protected $table = 'adjustment_approval_step';

    protected $guarded = [];

    protected $casts = ['decided_at' => 'datetime'];
}
