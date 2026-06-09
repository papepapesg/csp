<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.9 NOC force-sync request (approval-gated corrective re-push). */
class ProvisioningForceSyncRequest extends Model
{
    use HasPrefixedId;

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const APPROVED = 'APPROVED';

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const CANCELLED = 'CANCELLED';

    protected $table = 'provisioning_force_sync_request';

    protected $primaryKey = 'force_sync_id';

    protected string $idPrefix = 'pfs';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'force_sync_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
