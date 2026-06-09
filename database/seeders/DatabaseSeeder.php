<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Database\Seeders\DunningPolicySeeder;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Modules\Catalog\Database\Seeders\TaxCatalogSeeder;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Ilm\Database\Seeders\CvmPolicySeeder;
use Modules\Osr\Database\Seeders\OsrRmaSeeder;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\RestrictionCatalogSeeder;
use Modules\Subscription\Database\Seeders\OperationConfigSeeder;
use Modules\Subscription\Database\Seeders\PerOperationConfigSeeder;
use Modules\Subscription\Database\Seeders\StatusCatalogSeeder;
use Modules\Subscription\Database\Seeders\UpgradeConfigSeeder;
use Modules\Ticketing\Database\Seeders\AsrPolicySeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Modules\WorkOrder\Database\Seeders\FieldAuditPolicySeeder;
use Modules\WorkOrder\Database\Seeders\WoFrameworkSeeder;
use Modules\WorkOrder\Database\Seeders\WoSupportSeeder;
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
            StatusCatalogSeeder::class,
            OperationConfigSeeder::class,
            PerOperationConfigSeeder::class,
            ProcessDefinitionSeeder::class,
            ProvisioningTargetSeeder::class,
            DecisionTableSeeder::class,
            CatalogPolicySeeder::class,
            WalletCatalogSeeder::class,
            TaxCatalogSeeder::class,
            DunningPolicySeeder::class,
            RestrictionCatalogSeeder::class,
            UpgradeConfigSeeder::class,
            WoSupportSeeder::class,
            WoFrameworkSeeder::class,
            FieldAuditPolicySeeder::class,
            OsrRmaSeeder::class,
            CvmPolicySeeder::class,
            SlaPolicySeeder::class,
            AsrPolicySeeder::class,
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
