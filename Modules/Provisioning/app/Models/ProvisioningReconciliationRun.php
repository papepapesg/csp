<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** PROV-INT-01 §10.7 one reconciliation run. */
class ProvisioningReconciliationRun extends Model
{
    use HasPrefixedId;

    public const RUNNING = 'RUNNING';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    protected $table = 'provisioning_reconciliation_run';

    protected $primaryKey = 'run_id';

    protected string $idPrefix = 'prr';

    protected $guarded = [];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'run_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProvisioningReconciliationItem::class, 'run_id', 'run_id');
    }
}
