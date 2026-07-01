<?php

namespace Modules\Billing\Adjustments\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Billing\Adjustments\Models\AdjustmentRequest;

/**
 * BIL-02-ADJ-01 operator reason-code catalog — every adjustment must reference
 * an active reason whose direction (CREDIT / DEBIT / ANY) allows the request.
 */
class AdjustmentReasonCode extends Model
{
    /** Reason direction that justifies both CREDIT and DEBIT adjustments. */
    public const ANY = 'ANY';

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
        return $direction === AdjustmentRequest::CREDIT ? $this->credit_gl_code : $this->debit_gl_code;
    }

    /** Does this reason justify an adjustment in the given direction? */
    public function allows(string $direction): bool
    {
        return $this->direction === self::ANY || $this->direction === $direction;
    }
}
