<?php

namespace Modules\Billing\Adjustments\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-GEN-01 rule group R — one dual-approved bulk reversal operation:
 * PENDING_APPROVAL → IN_PROGRESS → COMPLETED / PARTIALLY_FAILED, or REJECTED.
 *
 * @property string $batch_id
 * @property string $status
 */
class BulkReversalBatch extends Model
{
    use HasPrefixedId;

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const IN_PROGRESS = 'IN_PROGRESS';

    public const COMPLETED = 'COMPLETED';

    public const PARTIALLY_FAILED = 'PARTIALLY_FAILED';

    public const REJECTED = 'REJECTED';

    /** EM-CFG-04 entity type of the batch's dual-control gate. */
    public const ENTITY_TYPE = 'BULK_REVERSAL';

    /** cancel_reason_code stamped on every invoice this batch voids (cancel provenance). */
    public const CANCEL_REASON = 'BULK_REVERSAL';

    protected $table = 'bulk_reversal_batch';

    protected $primaryKey = 'batch_id';

    protected string $idPrefix = 'brb';

    protected $guarded = [];

    protected $casts = [
        'filters' => 'array',
        're_issue' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'batch_id';
    }

    /** The scope array the batch was proposed with (feeds the same query at execute time). */
    public function scope(): array
    {
        return [
            'operator_code' => $this->operator_code,
            'invoice_type' => $this->invoice_type,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to,
            'filters' => $this->filters,
        ];
    }
}
