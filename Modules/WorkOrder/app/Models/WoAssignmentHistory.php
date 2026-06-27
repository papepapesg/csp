<?php

namespace Modules\WorkOrder\Models;
use Modules\WorkOrder\Models\WorkOrder;

use App\Foundation\Support\Id;
use Illuminate\Database\Eloquent\Model;

/** WO-01 §1.7 reassignment audit row (contractor/team/tech change at the same status). */
class WoAssignmentHistory extends Model
{
    protected $table = 'wo_assignment_history';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['changed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->id ??= Id::make('woasg'));
    }
}
