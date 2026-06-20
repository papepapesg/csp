<?php

namespace Modules\ItOps\Models;

use Illuminate\Database\Eloquent\Model;

/** Pending control command for a service (RESTART | PAUSE | RESUME). */
class ServiceControl extends Model
{
    protected $table = 'service_control';

    protected $primaryKey = 'service';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['requested_at' => 'datetime', 'acknowledged_at' => 'datetime'];
}
