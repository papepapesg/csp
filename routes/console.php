<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Forward committed transactional-outbox events to the event bus (FOUNDATION_KAFKA).
Schedule::command('sophix:outbox:dispatch')->everyMinute()->withoutOverlapping();

// BIL-03 cycle-close scanner (per-subscription boundary; recurring fee + usage).
Schedule::command('sophix:billing:cycle-close')->everyThirtyMinutes()->withoutOverlapping();

// EM-03 CVM daily flag evaluator.
Schedule::command('sophix:cvm:evaluate-flags')->daily();

// BIL-05 wallet expiry sweep (daily, R-W-9).
Schedule::command('sophix:wallet:expire')->daily();

// BIL-04 dunning scanner (daily).
Schedule::command('sophix:billing:dunning-run')->daily();

// PROV-INT-01 async status worker (resolve ACCEPTED commands).
Schedule::command('sophix:provisioning:poll-async')->everyFiveMinutes()->withoutOverlapping();

// PROV-INT-01 reconciliation worker (hourly polling of network vs desired state).
Schedule::command('sophix:provisioning:reconcile')->hourly()->withoutOverlapping();

// SUB-WF-FRAMEWORK-01 operation timeout sweep (R-SUB-WF-FW-9/10).
Schedule::command('sophix:subscription:operation-timeouts')->everyMinute()->withoutOverlapping();
