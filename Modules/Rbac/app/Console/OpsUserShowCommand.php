<?php

namespace Modules\Rbac\Console;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Ops review: show one user's assigned roles and effective (role-derived + direct)
 * permissions on the `web` guard (read-only) — so support can see exactly what a
 * person can do before anyone touches a grant. Wraps Spatie's HasRoles helpers.
 */
class OpsUserShowCommand extends Command
{
    protected $signature = 'sophix:rbac:user-show {user : The user uid or email}';

    protected $description = 'Review: show a user\'s roles and effective permissions (read-only)';

    public function handle(): int
    {
        $key = $this->argument('user');
        $user = User::query()->where('uid', $key)->orWhere('email', $key)->first();
        if (! $user) {
            $this->warn("No user found for uid/email '{$key}'.");

            return self::SUCCESS;
        }

        $this->info("User {$user->uid} ({$user->email})");
        $this->table(['Field', 'Value'], [
            ['uid', $user->uid],
            ['name', $user->name],
            ['email', $user->email],
            ['operator_code', $user->operator_code],
            ['status', $user->status],
        ]);

        $roles = $user->getRoleNames();
        $this->line('');
        $this->info('Roles: '.($roles->isEmpty() ? '—' : $roles->implode(', ')));

        $permissions = $user->getAllPermissions();
        if ($permissions->isEmpty()) {
            $this->warn('Effective permissions: none.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info("Effective permissions ({$permissions->count()}):");
        $this->table(
            ['Permission', 'Guard'],
            $permissions->map(fn ($p) => [$p->name, $p->guard_name])->all(),
        );

        return self::SUCCESS;
    }
}
