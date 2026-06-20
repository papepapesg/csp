<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Forward committed transactional-outbox events to the event bus (FOUNDATION_KAFKA).
Schedule::command('sophix:outbox:dispatch')->everyMinute()->withoutOverlapping();

// FOUNDATION_CAMUNDA tick: fire due workflow timers + release expired external-task
// locks (so timer nodes advance and a dead worker's locked tasks are re-queued).
Schedule::command('sophix:workflow:tick')->everyMinute()->withoutOverlapping();

// BIL-03 cycle-close scanner (per-subscription boundary; recurring fee + usage).
Schedule::command('sophix:billing:cycle-close')->everyThirtyMinutes()->withoutOverlapping();

// BIL-02-GEN-01 pro-forma pre-cycle scanner (prepaid, daily).
Schedule::command('sophix:billing:pro-forma')->daily();

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

// OSR-01 reservation-expiry sweep (R-OSR-SC-7, every 10 min).
Schedule::command('sophix:stock:expire-reservations')->everyTenMinutes()->withoutOverlapping();
