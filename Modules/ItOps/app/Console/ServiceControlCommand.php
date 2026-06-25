<?php

namespace Modules\ItOps\Console;

use Illuminate\Console\Command;
use Modules\ItOps\Models\ServiceControl;

/**
 * Ops safe-correction: queue a control command for a platform worker — the same
 * RESTART/PAUSE/RESUME flags the IT-Ops/NOC console writes and that workers
 * honour via Heartbeat::shouldStop(). This only writes the control flag (no
 * approval gate); the worker acts on it on its next loop. PAUSE keeps a worker
 * DOWN across restarts, so it requires --confirm.
 */
class ServiceControlCommand extends Command
{
    protected $signature = 'sophix:itops:service-control
        {service : The service name (e.g. workflow-worker)}
        {command : restart|pause|resume}
        {--actor=cli-ops : Recorded as requested_by on the control row}
        {--confirm : Required for pause (holds the worker DOWN across restarts)}';

    protected $description = 'Safe-correction: queue a restart/pause/resume control for a worker';

    private const COMMANDS = ['restart' => 'RESTART', 'pause' => 'PAUSE', 'resume' => 'RESUME'];

    public function handle(): int
    {
        $service = (string) $this->argument('service');
        $input = strtolower((string) $this->argument('command'));
        $actor = (string) $this->option('actor');

        if (! isset(self::COMMANDS[$input])) {
            $this->error("Unknown command '{$input}'. Use one of: ".implode(', ', array_keys(self::COMMANDS)).'.');

            return self::FAILURE;
        }
        $command = self::COMMANDS[$input];

        if ($command === 'PAUSE' && ! $this->option('confirm')) {
            $this->error("'pause' holds the worker DOWN across restarts; re-run with --confirm.");

            return self::FAILURE;
        }

        ServiceControl::query()->updateOrCreate(
            ['service' => $service],
            ['command' => $command, 'requested_by' => $actor, 'requested_at' => now(), 'acknowledged_at' => null],
        );

        $this->info("service-control: '{$command}' queued for {$service} by {$actor}.");
        $this->call('sophix:itops:service-show', ['service' => $service]);

        return self::SUCCESS;
    }
}
