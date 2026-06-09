<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.2 per-target adapter binding (which vendor class dispatches to a target). */
class ProvisioningAdapterConfig extends Model
{
    use HasPrefixedId;

    protected $table = 'provisioning_adapter_config';

    protected $primaryKey = 'adapter_config_id';

    protected string $idPrefix = 'pac';

    protected $guarded = [];

    protected $casts = ['retry_policy_json' => 'array', 'timeout_ms' => 'integer', 'max_retry_count' => 'integer'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    /** Resolve the ACTIVE adapter binding for a target (operator-scoped), or null. */
    public static function forTarget(string $operator, string $targetCode): ?self
    {
        return static::query()
            ->where('operator_code', $operator)
            ->where('target_code', $targetCode)
            ->where('status', 'ACTIVE')
            ->first();
    }
}
