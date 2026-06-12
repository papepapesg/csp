<?php

namespace App\Models;

use App\Foundation\Support\Context;
use Illuminate\Database\Eloquent\Model;

/** EM-01 / SALES-01 / USSD — ussd_session. */
class UssdSession extends Model
{
    protected $table = 'ussd_session';

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const ACTIVE = 'ACTIVE';
    public const ENDED = 'ENDED';
    public const TIMED_OUT = 'TIMED_OUT';

    protected $casts = ['context' => 'array', 'active' => 'boolean', 'session_data_json' => 'array', 'started_at' => 'datetime', 'last_seen_at' => 'datetime', 'ended_at' => 'datetime'];

    public function getRouteKeyName(): string
    {
        return 'session_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
