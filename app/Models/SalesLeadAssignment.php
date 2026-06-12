<?php

namespace App\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SALES-01 lead assignment history (DD §6.3). One active row per lead. */
class SalesLeadAssignment extends Model
{
    use HasPrefixedId;

    protected $table = 'sales_lead_assignment';
    protected $primaryKey = 'assignment_id';
    protected string $idPrefix = 'assn';
    protected $guarded = [];
    protected $casts = ['active' => 'bool', 'assigned_at' => 'datetime'];
}
