<?php

namespace Modules\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** EM-02 §3.5 availability slot — day/hour window with a concurrency cap. */
class ContractorAvailabilitySlot extends Model
{
    protected $table = 'contractor_availability_slot';

    protected $primaryKey = 'slot_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['max_concurrent' => 'integer', 'emergency_only' => 'boolean', 'active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];

    public function getRouteKeyName(): string
    {
        return 'slot_id';
    }
}
