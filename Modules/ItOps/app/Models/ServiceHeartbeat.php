<?php

namespace Modules\ItOps\Models;

use Illuminate\Database\Eloquent\Model;

/** Worker/service heartbeat. */
class ServiceHeartbeat extends Model
{
    protected $table = 'service_heartbeat';

    protected $primaryKey = 'service';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['metrics' => 'array', 'last_seen_at' => 'datetime'];
}
