<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.8 one desired-vs-observed mismatch (NOC review / force-sync). */
class ProvisioningReconciliationItem extends Model
{
    use HasPrefixedId;

    public const OPEN = 'OPEN';

    public const IN_REVIEW = 'IN_REVIEW';

    public const RESOLVED = 'RESOLVED';

    public const IGNORED = 'IGNORED';

    protected $table = 'provisioning_reconciliation_item';

    protected $primaryKey = 'item_id';

    protected string $idPrefix = 'pri';

    protected $guarded = [];

    protected $casts = ['diff' => 'array', 'resolved_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'item_id';
    }
}
