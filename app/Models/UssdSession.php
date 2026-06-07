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

    protected $casts = ['context' => 'array', 'active' => 'boolean'];

    public function getRouteKeyName(): string
    {
        return 'session_id';
    }

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->operator_code ??= Context::operatorCode());
    }
}
