<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Database\Seeders\AdjustmentConfigSeeder;
use Modules\Billing\Intent\Database\Seeders\BillableEventSeeder;
use Modules\Billing\Database\Seeders\DunningPolicySeeder;
use Modules\Billing\Database\Seeders\InvoiceGroupingSeeder;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Modules\Catalog\Database\Seeders\TaxCatalogSeeder;
use Modules\Billing\Wallet\Database\Seeders\WalletTypeSeeder;
use Modules\Fulfillment\Database\Seeders\FulfillmentFlowSeeder;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Database\Seeders\CvmPolicySeeder;
use Modules\Osr\Database\Seeders\OsrRmaSeeder;
use Modules\Osr\Database\Seeders\StockReasonCodeSeeder;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\RestrictionCatalogSeeder;
use Modules\Subscription\Database\Seeders\OperationConfigSeeder;
use Modules\Subscription\Database\Seeders\PerOperationConfigSeeder;
use Modules\Subscription\Database\Seeders\StatusCatalogSeeder;
use Modules\Subscription\Database\Seeders\UpgradeConfigSeeder;
use Modules\Ticketing\Database\Seeders\AsrPolicySeeder;
use Modules\Ticketing\Database\Seeders\TicketCategorySeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Modules\WorkOrder\Database\Seeders\FieldAuditPolicySeeder;
use Modules\Notification\Database\Seeders\TemplateCatalogSeeder;
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
            OperatorConfigSeeder::class,
            UiTranslationSeeder::class,
            StatusCatalogSeeder::class,
            OperationConfigSeeder::class,
            PerOperationConfigSeeder::class,
            ProcessDefinitionSeeder::class,
            FulfillmentFlowSeeder::class,
            ProvisioningTargetSeeder::class,
            DecisionTableSeeder::class,
            CatalogPolicySeeder::class,
            \Modules\Catalog\Database\Seeders\HomePassStatusSeeder::class,
            WalletTypeSeeder::class,
            TaxCatalogSeeder::class,
            DunningPolicySeeder::class,
            AdjustmentConfigSeeder::class,
            BillableEventSeeder::class,
            InvoiceGroupingSeeder::class,
            RestrictionCatalogSeeder::class,
            UpgradeConfigSeeder::class,
            WoSupportSeeder::class,
            WoFrameworkSeeder::class,
            \Modules\WorkOrder\Database\Seeders\WoKeJobTypeCatalogSeeder::class,
            FieldAuditPolicySeeder::class,
            OsrRmaSeeder::class,
            StockReasonCodeSeeder::class,
            CvmPolicySeeder::class,
            AccountFlagCatalogSeeder::class,
            SlaPolicySeeder::class,
            AsrPolicySeeder::class,
            TicketCategorySeeder::class,
            TemplateCatalogSeeder::class,
            // Demo-friendly sellable catalog (services, packages incl. pkg_fiber_100m,
            // bundles, promo) so catalog surfaces and the demo journeys have real data.
            \Modules\Catalog\Database\Seeders\CatalogDemoSeeder::class,
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
