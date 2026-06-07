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

    public const PENDING = 'PENDING';

    public const SENT = 'SENT';

    public const CONFIRMED = 'CONFIRMED';

    public const FAILED = 'FAILED';

    public const MISMATCH = 'MISMATCH';

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
