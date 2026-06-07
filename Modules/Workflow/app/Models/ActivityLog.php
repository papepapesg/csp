<?php

namespace Modules\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/** Engine activity log — powers live process tracing in IT-Ops. */
class ActivityLog extends Model
{
    protected $table = 'workflow_activity_log';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['data' => 'array', 'created_at' => 'datetime'];
}
