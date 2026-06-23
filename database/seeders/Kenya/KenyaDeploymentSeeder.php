<?php

namespace Database\Seeders\Kenya;

use App\Models\User;
use Database\Seeders\OperatorConfigSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\UiTranslationSeeder;
use Database\Seeders\UssdMenuSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Modules\Billing\Database\Seeders\AdjustmentConfigSeeder;
use Modules\Billing\Database\Seeders\BillableEventSeeder;
use Modules\Billing\Database\Seeders\DunningPolicySeeder;
use Modules\Billing\Database\Seeders\InvoiceGroupingSeeder;
use Modules\Catalog\Database\Seeders\CatalogPolicySeeder;
use Modules\Catalog\Database\Seeders\HomePassStatusSeeder;
use Modules\Catalog\Database\Seeders\TaxCatalogSeeder;
use Modules\Catalog\Database\Seeders\WalletCatalogSeeder;
use Modules\Fulfillment\Database\Seeders\FulfillmentFlowSeeder;
use Modules\Ilm\Database\Seeders\AccountFlagCatalogSeeder;
use Modules\Ilm\Database\Seeders\CvmPolicySeeder;
use Modules\Notification\Database\Seeders\TemplateCatalogSeeder;
use Modules\Osr\Database\Seeders\OsrRmaSeeder;
use Modules\Osr\Database\Seeders\StockReasonCodeSeeder;
use Modules\Provisioning\Database\Seeders\ProvisioningTargetSeeder;
use Modules\Rules\Database\Seeders\DecisionTableSeeder;
use Modules\Subscription\Database\Seeders\OperationConfigSeeder;
use Modules\Subscription\Database\Seeders\PerOperationConfigSeeder;
use Modules\Subscription\Database\Seeders\RestrictionCatalogSeeder;
use Modules\Subscription\Database\Seeders\StatusCatalogSeeder;
use Modules\Subscription\Database\Seeders\UpgradeConfigSeeder;
use Modules\Ticketing\Database\Seeders\AsrPolicySeeder;
use Modules\Ticketing\Database\Seeders\SlaPolicySeeder;
use Modules\Ticketing\Database\Seeders\TicketCategorySeeder;
use Modules\WorkOrder\Database\Seeders\FieldAuditPolicySeeder;
use Modules\WorkOrder\Database\Seeders\WoFrameworkSeeder;
use Modules\WorkOrder\Database\Seeders\WoKeJobTypeCatalogSeeder;
use Modules\WorkOrder\Database\Seeders\WoSupportSeeder;
use Modules\Workflow\Database\Seeders\ProcessDefinitionSeeder;

/**
 * Wananchi Kenya (Zuku) country deployment — built ENTIRELY on the generic SOPHIX
 * platform via configuration + seed data. No platform/module code is modified.
 *
 * It runs the platform's configuration seeders (RBAC, operator config, status &
 * operation catalogs, workflow process definitions, rules decision tables, tax,
 * wallets, dunning, work-order framework + KE job types, OSR, ticketing/SLA,
 * notification templates, …) and then layers the Kenya-specific configuration:
 * the Zuku commercial catalog, network topology, dual-wallet billing policy, the
 * TV provisioning plane and a small operational sample.
 *
 * Demo/POC fixtures (Senegal POC dispatcher, demo journeys, demo catalog) are
 * deliberately excluded — this is a clean Kenya deployment.
 *
 * Run:  php artisan db:seed --class="Database\\Seeders\\Kenya\\KenyaDeploymentSeeder" --force
 */
class KenyaDeploymentSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Platform configuration (operator-agnostic engine catalogs) ────
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
            HomePassStatusSeeder::class,
            WalletCatalogSeeder::class,
            TaxCatalogSeeder::class,
            DunningPolicySeeder::class,
            AdjustmentConfigSeeder::class,
            BillableEventSeeder::class,
            InvoiceGroupingSeeder::class,
            RestrictionCatalogSeeder::class,
            UpgradeConfigSeeder::class,
            WoSupportSeeder::class,
            WoFrameworkSeeder::class,
            WoKeJobTypeCatalogSeeder::class,
            FieldAuditPolicySeeder::class,
            OsrRmaSeeder::class,
            StockReasonCodeSeeder::class,
            CvmPolicySeeder::class,
            AccountFlagCatalogSeeder::class,
            SlaPolicySeeder::class,
            AsrPolicySeeder::class,
            TicketCategorySeeder::class,
            TemplateCatalogSeeder::class,
            UssdMenuSeeder::class,
        ]);

        // ── 2. Kenya (Wananchi/Zuku) deployment configuration ────────────────
        $this->call([
            KenyaTaxSeeder::class,              // service taxed, usage exempt (WIK-scoped)
            KenyaCommercialCatalogSeeder::class,// Zuku packages / bundles / dual-wallet routing
            KenyaTechRegionSeeder::class,       // Kenya regions + serviceable home passes
            KenyaBillingConfigSeeder::class,    // dual-wallet invoice grouping
            KenyaProvisioningSeeder::class,     // Verimatrix TV plane (stub adapter)
            KenyaSampleCustomersSeeder::class,  // live CAS sample (incl. NPD + triple-play)
        ]);

        // ── 3. Deployment admin user ─────────────────────────────────────────
        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@zuku.co.ke'],
            ['name' => 'Wananchi Kenya Administrator', 'password' => Hash::make('password'), 'operator_code' => 'WIK'],
        );
        $admin->assignRole('SUPER_ADMIN');
    }
}
