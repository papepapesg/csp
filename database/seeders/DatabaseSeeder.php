<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Billing\Database\Seeders\DunningPolicySeeder;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RbacSeeder::class,
            ProcessDefinitionSeeder::class,
            ProvisioningTargetSeeder::class,
            DecisionTableSeeder::class,
            CatalogPolicySeeder::class,
            DunningPolicySeeder::class,
            SlaPolicySeeder::class,
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@sophix.local'],
            [
                'name' => 'SOPHIX Administrator',
                'password' => Hash::make('password'),
                'operator_code' => config('sophix.default_operator'),
            ],
        );
        $admin->assignRole('SUPER_ADMIN');
    }
}
