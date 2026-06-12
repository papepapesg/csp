<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SALES-01 conversion link from lead to FUL order / customer / subscription (DD §6.5). */
class SalesConversion extends Model
{
    use HasPrefixedId;

    protected $table = 'sales_conversion';
    protected $primaryKey = 'conversion_id';
    protected string $idPrefix = 'conv';
    protected $guarded = [];
    protected $casts = ['converted_at' => 'datetime'];
}
