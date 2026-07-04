<?php

namespace Modules\Billing\Dunning\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * BIL-04 dunning escalation state for an account.
 *
 * @property string $dunning_id
 * @property int $current_level
 */
class DunningState extends Model
{
    use HasPrefixedId;

    public const LEVEL_NONE = 0;

    public const LEVEL_WARNING = 1;

    public const LEVEL_RESTRICTED = 2;

    public const LEVEL_SUSPENDED = 3;

    public const LEVEL_TERMINATED = 4;

    // Statuses (BIL-04 D-4 / T-4 / T-6).
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CLEARED = 'CLEARED';

    public const STATUS_PENDING_TERMINATION_REVIEW = 'PENDING_TERMINATION_REVIEW';

    public const STATUS_SUSPENDED_BY_PAUSE = 'SUSPENDED_BY_PAUSE';

    public const STATUS_RECOVERY_FAILED = 'RECOVERY_FAILED';

    public const STATUS_ARCHIVED = 'ARCHIVED';

    // Triggering event types — what put the account into dunning (T-1).
    public const TRIGGER_INVOICE_OVERDUE = 'INVOICE_OVERDUE';

    public const TRIGGER_CYCLE_PAYMENT_MISSED = 'CYCLE_PAYMENT_MISSED';

    // Archive reasons (D-4).
    public const ARCHIVE_CLEARED = 'CLEARED_FULLY_PAID';

    public const ARCHIVE_ADMIN_CLEARED = 'ADMIN_CLEARED';

    public const ARCHIVE_TERMINATED = 'TERMINATED';

    // Admin override actions (R-5, audit labels on DunningAdminOverride).
    public const ADMIN_CLEAR_WITHOUT_PAYMENT = 'CLEAR_WITHOUT_PAYMENT';

    public const ADMIN_HOLD = 'HOLD';

    public const ADMIN_ADVANCE = 'ADVANCE';

    public const ADMIN_FORCE_TERMINATE = 'FORCE_TERMINATE';

    protected $table = 'dunning_state';

    protected $primaryKey = 'dunning_id';

    protected string $idPrefix = 'dun';

    protected $guarded = [];

    protected $casts = [
        'current_level' => 'integer',
        'dunning_program_version' => 'integer',
        'workflow_failure_attempts' => 'integer',
        'outstanding_debt_amount' => 'decimal:2',
        'applied_restriction_codes' => 'array',
        'entered_level_at' => 'datetime',
        'entered_dunning_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'next_evaluation_at' => 'datetime',
        'review_due_at' => 'datetime',
        'last_workflow_failure_at' => 'datetime',
        'cleared_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    public function program(): ?DunningProgram
    {
        if (! $this->dunning_program_ref) {
            return null;
        }

        return DunningProgram::query()->where('code', $this->dunning_program_ref)
            ->where('version', $this->dunning_program_version)->first();
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
