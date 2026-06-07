<?php

namespace Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;

/** REP-01 daily metric mart row. */
class ReportDailyMetric extends Model
{
    protected $table = 'report_daily_metric';

    protected $guarded = [];

    protected $casts = ['metric_date' => 'date', 'value' => 'decimal:2'];
}
