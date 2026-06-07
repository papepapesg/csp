<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.6 latest observed state fetched from a target. */
class ProvisioningObservedState extends Model
{
    use HasPrefixedId;

    protected $table = 'provisioning_observed_state';

    protected $primaryKey = 'observed_state_id';

    protected string $idPrefix = 'pos';

    protected $guarded = [];

    protected $casts = ['observed_profile' => 'array', 'collected_at' => 'datetime'];
}
