<?php

namespace Modules\Osr\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** OSR-INSTANCE-01 append-only instance lifecycle event. */
class EquipmentInstanceLifecycleEvent extends Model
{
    use HasPrefixedId;

    protected $table = 'equipment_instance_lifecycle_event';

    protected string $idPrefix = 'eqle';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
