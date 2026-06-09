<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Deployment wizard: one command stands up a working SOPHIX environment.
 *
 *   sophix:setup demo     fresh DB + full catalogs + sample demo data + admin/password
 *   sophix:setup preprod  fresh DB + full catalogs, no sample data, random admin password
 *   sophix:setup prod     migrate (non-destructive) + catalogs, random admin password
 */
class SophixSetupCommand extends Command
{
    protected $signature = 'sophix:setup
        {profile=demo : demo | preprod | prod}
        {--operator= : operator code (defaults to config sophix.default_operator)}
        {--admin-email=admin@sophix.local}';

    protected $description = 'Guided environment setup for demo, preprod or prod deployments';

    public function handle(): int
    {
        $profile = strtolower((string) $this->argument('profile'));
        if (! in_array($profile, ['demo', 'preprod', 'prod'], true)) {
            $this->error('Profile must be demo, preprod or prod.');

            return self::FAILURE;
        }
        $operator = $this->option('operator') ?: config('sophix.default_operator', 'WIK');

        $this->components->info("SOPHIX setup — profile: {$profile}, operator: {$operator}");

        // 1. Database schema.
        if ($profile === 'prod') {
            $this->components->task('Migrating database (non-destructive)', fn () => Artisan::call('migrate', ['--force' => true]) === 0);
        } else {
            if ($profile === 'preprod' && ! $this->confirm('preprod wipes the database (migrate:fresh). Continue?', true)) {
                return self::FAILURE;
            }
            $this->components->task('Rebuilding database (migrate:fresh)', fn () => Artisan::call('migrate:fresh', ['--force' => true]) === 0);
        }

        // 2. Catalogs + reference data (flows, rules, templates, RBAC, operator config…).
        $this->components->task('Seeding catalogs & reference data', fn () => Artisan::call('db:seed', ['--force' => true]) === 0);

        // 3. Admin account.
        $password = $profile === 'demo' ? 'password' : Str::password(16);
        $email = (string) $this->option('admin-email');
        $this->components->task('Creating admin user', function () use ($email, $password, $operator) {
            $admin = User::query()->updateOrCreate(
                ['email' => $email],
                ['name' => 'SOPHIX Administrator', 'password' => Hash::make($password), 'operator_code' => $operator],
            );
            $admin->syncRoles(['SUPER_ADMIN']);

            return true;
        });

        // 4. Demo sample data (a browsable customer journey).
        if ($profile === 'demo') {
            $this->components->task('Seeding demo journey (customer → order → active subscription)', function () {
                Artisan::call('db:seed', ['--class' => \Database\Seeders\DemoJourneySeeder::class, '--force' => true]);

                return true;
            });
        }

        $this->newLine();
        $this->components->twoColumnDetail('Backoffice', url('/'));
        $this->components->twoColumnDetail('Admin login', "{$email} / {$password}");
        $this->components->twoColumnDetail('Workers to run', 'php artisan sophix:workflow:work | sophix:outbox:dispatch | schedule:work');
        if ($profile !== 'demo') {
            $this->components->warn('Store the generated admin password now — it is not persisted anywhere else.');
        }

        return self::SUCCESS;
    }
}
