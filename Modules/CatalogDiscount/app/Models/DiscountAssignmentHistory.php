<?php

namespace Modules\Catalog\Discount\Models;

use App\Foundation\Models\HasPrefixedId;
use Illuminate\Database\Eloquent\Model;

/** SIP-03 immutable assignment status-change record (DD §6.5). */
class DiscountAssignmentHistory extends Model
{
    use HasPrefixedId;

    protected $table = 'discount_assignment_status_history';
    protected $primaryKey = 'history_id';
    protected string $idPrefix = 'dash';
    protected $guarded = [];
    protected $casts = ['changed_at' => 'datetime'];
}
