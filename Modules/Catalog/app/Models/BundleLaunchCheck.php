<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-04 §6.6 launch validation finding (PASS/WARN/FAIL). */
class BundleLaunchCheck extends Model
{
    use HasPrefixedId;

    protected $table = 'commercial_bundle_launch_check';

    protected $primaryKey = 'check_id';

    protected string $idPrefix = 'blc';

    protected $guarded = [];

    protected $casts = ['source_ref_json' => 'array', 'checked_at' => 'datetime'];

    public $timestamps = false;
}
