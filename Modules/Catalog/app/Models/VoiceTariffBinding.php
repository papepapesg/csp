<?php

namespace Modules\Catalog\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/**
 * PLM-CFG-07 §7.7 — binds a tariff plan to a package, service, or explicit
 * subscription override. Rating resolves the plan by binding scope precedence
 * (SUBSCRIPTION_OVERRIDE → PACKAGE → SERVICE), then highest priority wins.
 */
class VoiceTariffBinding extends Model
{
    use HasPrefixedId;

    public const SCOPE_PACKAGE = 'PACKAGE';

    public const SCOPE_SERVICE = 'SERVICE';

    public const SCOPE_SUBSCRIPTION_OVERRIDE = 'SUBSCRIPTION_OVERRIDE';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_RETIRED = 'RETIRED';

    protected $table = 'voice_tariff_binding';

    protected $primaryKey = 'binding_id';

    protected string $idPrefix = 'vbn';

    protected $guarded = [];

    protected $casts = [
        'priority' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];
}
