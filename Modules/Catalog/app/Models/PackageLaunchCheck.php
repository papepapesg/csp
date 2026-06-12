<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * SIP-02 §5.2 package launch check — one validation finding for a launch plan.
 * check_status is PASS / WARN / FAIL; any FAIL blocks review/activation.
 */
class PackageLaunchCheck extends Model
{
    use HasPrefixedId;

    public const STATUS_PASS = 'PASS';

    public const STATUS_WARN = 'WARN';

    public const STATUS_FAIL = 'FAIL';

    protected $table = 'package_launch_check';

    protected $primaryKey = 'check_id';

    protected string $idPrefix = 'plc';

    protected $guarded = [];

    protected $casts = [
        'source_ref_json' => 'array',
        'checked_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'check_id';
    }
}
