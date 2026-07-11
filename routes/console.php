<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Forward committed transactional-outbox events to the event bus (FOUNDATION_KAFKA).
Schedule::command('sophix:outbox:dispatch')->everyMinute()->withoutOverlapping();

// Capability-specific schedules are registered by their owning module providers.
// Keeping only platform-wide jobs here means disabling/extracting a module also
// disables/extracts its workers without editing the application scheduler.
