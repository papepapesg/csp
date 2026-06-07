<?php

namespace Modules\Provisioning\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** PROV-INT-01 §10.5 desired technical state (the reconciliation comparison base). */
class ProvisioningDesiredState extends Model
{
    use HasPrefixedId;

    protected $table = 'provisioning_desired_state';

    protected $primaryKey = 'desired_state_id';

    protected string $idPrefix = 'pds';

    protected $guarded = [];

    protected $casts = ['desired_profile' => 'array', 'effective_from' => 'datetime'];
}
