<?php

namespace Modules\ItOps\Models;

use Illuminate\Database\Eloquent\Model;

/** Searchable structured log row. */
class SystemLog extends Model
{
    protected $table = 'system_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['context' => 'array', 'logged_at' => 'datetime'];
}
