<?php

namespace Modules\ItOps\Console;

use Illuminate\Console\Command;
use Modules\ItOps\Models\ServiceControl;
use Modules\ItOps\Models\ServiceHeartbeat;
use Modules\ItOps\Models\SystemLog;

/**
 * Ops review: a one-glance health summary of the platform's workers — each
 * service's liveness (UP/DOWN by the same 120s heartbeat threshold the IT-Ops
 * services API uses), any pending control command, plus error/critical log
 * counts in the recent window (read-only).
 */
class OpsStatusCommand extends Command
{
    protected $signature = 'sophix:itops:ops-status {--minutes=60 : Log error/critical window, in minutes}';

    protected $description = 'Review: service heartbeats, pending controls and recent error counts (read-only)';

    public function handle(): int
    {
        $threshold = now()->subSeconds(120);
        $services = ServiceHeartbeat::query()->orderBy('service')->get();
        $controls = ServiceControl::query()->whereNotNull('command')->get()->keyBy('service');

        $rows = $services->map(function ($s) use ($threshold, $controls) {
            $up = $s->last_seen_at && $s->last_seen_at->gt($threshold);

            return [
                $s->service,
                $up ? 'UP' : 'DOWN',
                $s->instance_id ?? '—',
                $s->last_seen_at ? (string) $s->last_seen_at : '—',
                $controls->has($s->service) ? $controls[$s->service]->command : '—',
            ];
        })->all();

        $this->info('IT-Ops service status (DOWN = no heartbeat in last 120s)');
        if ($rows) {
            $this->table(['Service', 'Liveness', 'Instance', 'Last seen', 'Pending control'], $rows);
        } else {
            $this->warn('No service heartbeats recorded.');
        }

        $down = $services->filter(fn ($s) => ! ($s->last_seen_at && $s->last_seen_at->gt($threshold)))->count();
        if ($down > 0) {
            $this->warn("{$down} service(s) DOWN.");
        }

        $minutes = max(1, (int) $this->option('minutes'));
        $since = now()->subMinutes($minutes);
        $logRows = [
            ['Errors (last '.$minutes.'m)', SystemLog::query()->where('level', 'error')->where('logged_at', '>=', $since)->count()],
            ['Criticals (last '.$minutes.'m)', SystemLog::query()->where('level', 'critical')->where('logged_at', '>=', $since)->count()],
        ];
        $this->table(['Log signal', 'Count'], $logRows);

        $this->line('Drill in: sophix:itops:service-show <service> · control: sophix:itops:service-control');

        return self::SUCCESS;
    }
}
