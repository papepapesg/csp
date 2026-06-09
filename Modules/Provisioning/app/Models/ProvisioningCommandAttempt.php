<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.4 per-attempt dispatch ledger for a provisioning command. */
class ProvisioningCommandAttempt extends Model
{
    use HasPrefixedId;

    public $timestamps = false;

    protected $table = 'provisioning_command_attempt';

    protected $primaryKey = 'attempt_id';

    protected string $idPrefix = 'pcma';

    protected $guarded = [];

    protected $casts = ['response_payload' => 'array', 'attempt_no' => 'integer', 'duration_ms' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
