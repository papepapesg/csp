<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Forward committed transactional-outbox events to the event bus (FOUNDATION_KAFKA).
Schedule::command('sophix:outbox:dispatch')->everyMinute()->withoutOverlapping();

// BIL-04 dunning scanner (daily).
Schedule::command('sophix:billing:dunning-run')->daily();
