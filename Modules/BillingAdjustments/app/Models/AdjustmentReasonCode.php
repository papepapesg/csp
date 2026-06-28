<?php

namespace Modules\Billing\Adjustments\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BIL-02-ADJ-01 operator reason-code catalog — every adjustment must reference
 * an active reason whose direction (CREDIT / DEBIT / ANY) allows the request.
 */
class AdjustmentReasonCode extends Model
{
    protected $table = 'adjustment_reason_code';

    protected $guarded = [];

    protected $casts = ['active' => 'boolean'];

    public static function activeByCode(string $operator, string $code): ?self
    {
        return static::query()
            ->where('operator_code', $operator)
            ->where('code', $code)
            ->where('active', true)
            ->first();
    }

    /** The GL posting account for a given adjustment direction (CREDIT/DEBIT). */
    public function glFor(string $direction): ?string
    {
        return $direction === 'CREDIT' ? $this->credit_gl_code : $this->debit_gl_code;
    }
}
