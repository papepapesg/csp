<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SALES-01 sales activity — visit/call/follow-up (DD §6.4). */
class SalesActivity extends Model
{
    use HasPrefixedId;

    public $timestamps = false;
    protected $table = 'sales_activity';
    protected $primaryKey = 'activity_id';
    protected string $idPrefix = 'sact';
    protected $guarded = [];
    protected $casts = ['next_follow_up_at' => 'datetime', 'created_at' => 'datetime'];
}
