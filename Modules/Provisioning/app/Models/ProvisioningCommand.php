<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/**
 * PROV-INT-01 provisioning command ledger row.
 *
 * @property string $command_id
 * @property string $status
 */
class ProvisioningCommand extends Model
{
    use HasPrefixedId;

    // Legacy aliases (kept for existing callers/tests).
    public const PENDING = 'PENDING';

    public const SENT = 'SENT';

    public const CONFIRMED = 'CONFIRMED';

    public const FAILED = 'FAILED';

    public const MISMATCH = 'MISMATCH';

    // PROV-INT-01 §9 canonical command status model.
    public const RECEIVED = 'RECEIVED';

    public const DISPATCHING = 'DISPATCHING';

    public const ACCEPTED = 'ACCEPTED';            // §7.2 async: vendor accepted, result pending

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED_RETRYABLE = 'FAILED_RETRYABLE';

    public const FAILED_FINAL = 'FAILED_FINAL';

    public const TIMED_OUT = 'TIMED_OUT';

    public const SUPERSEDED = 'SUPERSEDED';

    protected $table = 'provisioning_command';

    protected $primaryKey = 'command_id';

    protected string $idPrefix = 'pcmd';

    protected $guarded = [];

    protected $casts = [
        'desired_state' => 'array', 'observed_state' => 'array',
        'request' => 'array', 'response' => 'array',
        'sent_at' => 'datetime', 'confirmed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'command_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
