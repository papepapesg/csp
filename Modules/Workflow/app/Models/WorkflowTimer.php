<?php

namespace Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/** Timer — instance waits until fire_at. */
class WorkflowTimer extends Model
{
    protected $table = 'workflow_timer';

    protected $guarded = [];

    protected $casts = ['fire_at' => 'datetime'];
}
