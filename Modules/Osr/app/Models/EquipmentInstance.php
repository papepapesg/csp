<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * OSR-INSTANCE-01 serialized equipment instance.
 *
 * @property string $instance_id
 * @property string $state
 */
class EquipmentInstance extends Model
{
    use HasPrefixedId;

    public const IN_MAIN_WAREHOUSE = 'IN_MAIN_WAREHOUSE';

    public const IN_CONTRACTOR_STOCK = 'IN_CONTRACTOR_STOCK';

    public const IN_FIELD_ACTIVE = 'IN_FIELD_ACTIVE';

    public const RETURNED = 'RETURNED';

    public const FAULTY = 'FAULTY';

    public const RETIRED = 'RETIRED';

    protected $table = 'equipment_instance';

    protected $primaryKey = 'instance_id';

    protected string $idPrefix = 'eqi';

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'instance_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }

    public function lifecycleEvents(): HasMany
    {
        return $this->hasMany(EquipmentInstanceLifecycleEvent::class, 'instance_id', 'instance_id');
    }
}
