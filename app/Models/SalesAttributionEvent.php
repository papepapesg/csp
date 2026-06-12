<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SALES-01 immutable attribution event — the commission basis (DD §6.7, SALES-5). Append-only. */
class SalesAttributionEvent extends Model
{
    use HasPrefixedId;

    protected $table = 'sales_attribution_event';
    protected $primaryKey = 'attribution_event_id';
    protected string $idPrefix = 'satt';
    protected $guarded = [];
    protected $casts = ['payload_json' => 'array', 'occurred_at' => 'datetime'];
}
