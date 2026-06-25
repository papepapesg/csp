<?php

namespace Modules\Rbac\Console;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * Ops review: list every Spatie role with its guard and the number of permissions
 * attached to it (read-only) — a one-glance map of the role catalog for support to
 * eyeball before inspecting an individual user. Wraps the Spatie Role model.
 */
class OpsRolesCommand extends Command
{
    protected $signature = 'sophix:rbac:roles {--guard= : Scope to one guard (default: all)}';

    protected $description = 'Review: list roles and their permission counts (read-only)';

    public function handle(): int
    {
        $guard = $this->option('guard');
        $roles = Role::query()
            ->when($guard, fn ($q) => $q->where('guard_name', $guard))
            ->withCount('permissions')
            ->orderBy('name')
            ->get();

        if ($roles->isEmpty()) {
            $this->warn('No roles found'.($guard ? " for guard {$guard}." : '.'));

            return self::SUCCESS;
        }

        $this->info('Roles'.($guard ? " — guard {$guard}" : ' — all guards'));
        $this->table(
            ['Role', 'Guard', 'Permissions'],
            $roles->map(fn ($r) => [$r->name, $r->guard_name, $r->permissions_count])->all(),
        );
        $this->line('Inspect one user: sophix:rbac:user-show <uid|email>');

        return self::SUCCESS;
    }
}
