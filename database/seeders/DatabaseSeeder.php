<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
