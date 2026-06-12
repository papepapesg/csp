<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** FE-CH-USSD-01 per-request trace for support/dispute handling. */
class UssdRequestLog extends Model
{
    use HasPrefixedId;

    public $timestamps = false;
    protected $table = 'ussd_request_log';
    protected $primaryKey = 'request_log_id';
    protected string $idPrefix = 'url';
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];
}
