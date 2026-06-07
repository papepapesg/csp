<?php

namespace Modules\Billing\Models;

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

    protected $table = 'dunning_state';

    protected $primaryKey = 'dunning_id';

    protected string $idPrefix = 'dun';

    protected $guarded = [];

    protected $casts = [
        'current_level' => 'integer',
        'outstanding_debt_amount' => 'decimal:2',
        'entered_level_at' => 'datetime',
        'last_scanned_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
