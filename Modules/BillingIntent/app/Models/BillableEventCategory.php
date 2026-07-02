<?php

namespace Modules\Billing\Intent\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-CFG-01 billable_event_category — finance-reporting classification
 * (LIFECYCLE_FEE, PRORATION, REFUND…), operator-scoped. Drives revenue-stream
 * separation; gl_account_hint is advisory only.
 */
class BillableEventCategory extends Model
{
    protected $table = 'billable_event_category';

    protected $guarded = [];

    protected $casts = ['is_credit' => 'boolean', 'retired_at' => 'datetime'];
}
