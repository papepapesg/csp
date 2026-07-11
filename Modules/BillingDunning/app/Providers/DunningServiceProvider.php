<?php

namespace Modules\Billing\Dunning\Providers;

use App\Foundation\Events\OutboxEventPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Billing\Dunning\Console\ArchiveDunningStatesCommand;
use Modules\Billing\Dunning\Console\DunningFixCommand;
use Modules\Billing\Dunning\Console\DunningRunCommand;
use Modules\Billing\Dunning\Console\DunningShowCommand;
use Modules\Billing\Dunning\Listeners\DunningEventBridge;

/** BIL-04 dunning module bootstrap — owns its schema, routes, scanner/admin commands and listener. */
class DunningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    public function boot(Schedule $schedule): void
    {
        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        // BIL-04: prepaid CyclePaymentMissed → enter dunning; pause/resume; WalletToppedUp → recovery.
        Event::listen(OutboxEventPublished::class, [DunningEventBridge::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([DunningRunCommand::class, ArchiveDunningStatesCommand::class, DunningShowCommand::class, DunningFixCommand::class]);
            $schedule->command('sophix:billing:dunning-run')->daily()->withoutOverlapping();
        }
    }
}
