<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use Illuminate\Database\Eloquent\Model;

/** WO-01 §4.4 finalize checklist per (operator, kind, job_type). */
class WoFinalizationRequirement extends Model
{
    protected $table = 'wo_finalization_requirements';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = [
        'required_note_kinds' => 'array',
        'required_attachment_categories' => 'array',
        'min_attachments_per_category' => 'array',
    ];

    /** Resolve the specific (job_type) row, falling back to the kind default (job_type_code null). */
    public static function resolveFor(string $operator, string $kind, ?string $jobType): ?self
    {
        return static::query()->where('operator_code', $operator)->where('kind', $kind)
            ->where('job_type_code', $jobType)->first()
            ?? static::query()->where('operator_code', $operator)->where('kind', $kind)
                ->whereNull('job_type_code')->first();
    }
}
