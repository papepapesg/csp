<?php

namespace Modules\Catalog\Database\Seeders;

use App\Foundation\Support\Context;
use Illuminate\Database\Seeder;
use Modules\Catalog\Models\BundleComponent;
use Modules\Catalog\Models\CommercialBundle;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageService;
use Modules\Catalog\Models\PackageVersion;
use Modules\Catalog\Models\PromoCampaign;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Models\ServiceClass;

/**
 * Demo catalog dataset (PLM-CFG-01 / SIP-01 / SIP-04). Seeds a small but realistic
 * sellable catalog so every catalog backoffice surface opens with data: service
 * classes, services, packages (with an ACTIVE priced version and service links),
 * commercial bundles and a promo campaign.
 *
 * Critically it seeds `pkg_fiber_100m` — the package id the demo customer journeys
 * reference — as a real, ACTIVE, priced catalog row. Idempotent (updateOrCreate),
 * so it is safe to run repeatedly and as part of DatabaseSeeder before the journeys.
 */
class CatalogDemoSeeder extends Seeder
{
    public function run(): void
    {
        $op = config('sophix.default_operator', 'WIK');
        Context::setOperatorCode($op);

        // ── PLM-CFG-01 service classes ───────────────────────────────────────
        $broadband = ServiceClass::query()->updateOrCreate(
            ['id' => 'scls_broadband'],
            ['operator_code' => $op, 'name' => 'Broadband', 'description' => 'Fixed broadband internet access',
             'requires_equipment' => true],
        );
        $tv = ServiceClass::query()->updateOrCreate(
            ['id' => 'scls_tv'],
            ['operator_code' => $op, 'name' => 'Television', 'description' => 'IPTV / pay-TV services',
             'requires_equipment' => true],
        );
        $voice = ServiceClass::query()->updateOrCreate(
            ['id' => 'scls_voice'],
            ['operator_code' => $op, 'name' => 'Voice', 'description' => 'Fixed voice / VoIP', 'requires_equipment' => false],
        );

        // ── PLM-CFG-01 services (the sellable / provisionable units) ──────────
        // svc_fiber_100m is the primary fiber access service; svc/value-add ones
        // give the package composition more than one row.
        $services = [
            ['svc_fiber_100m', 'Fiber 100 Mbps', 'FIBER_100M', $broadband->id, 'BROADBAND', true, 'PROVISIONER_OLT'],
            ['svc_fiber_200m', 'Fiber 200 Mbps', 'FIBER_200M', $broadband->id, 'BROADBAND', true, 'PROVISIONER_OLT'],
            ['svc_static_ip', 'Static IPv4', 'STATIC_IP', $broadband->id, 'BROADBAND', false, null],
            ['svc_iptv_basic', 'IPTV Basic', 'IPTV_BASIC', $tv->id, 'TV', false, 'PROVISIONER_IPTV'],
            ['svc_voice_line', 'Voice Line', 'VOICE_LINE', $voice->id, 'VOICE', false, 'PROVISIONER_VOICE'],
        ];
        $svcModels = [];
        foreach ($services as [$id, $name, $code, $classId, $group, $addressable, $provisioner]) {
            $svcModels[$id] = Service::query()->updateOrCreate(
                ['id' => $id],
                ['operator_code' => $op, 'name' => $name, 'code' => $code, 'service_class_id' => $classId,
                 'service_group' => $group, 'is_addressable' => $addressable, 'consumption_model' => 'FLAT',
                 'provisioner_key' => $provisioner, 'status' => 'ACTIVE'],
            );
        }

        // ── SIP-01 packages ──────────────────────────────────────────────────
        // [id, code, name, display, status, price, [service ids in composition]]
        $packages = [
            ['pkg_fiber_100m', 'FIBER_100M', 'Fiber Home 100', 'Fiber Home 100 Mbps', Package::STATUS_ACTIVE,
                3000.00, ['svc_fiber_100m', 'svc_iptv_basic']],
            ['pkg_fiber_200m', 'FIBER_200M', 'Fiber Home 200', 'Fiber Home 200 Mbps', Package::STATUS_ACTIVE,
                4500.00, ['svc_fiber_200m', 'svc_iptv_basic']],
            ['pkg_fiber_biz', 'FIBER_BIZ', 'Fiber Business', 'Fiber Business + Static IP', Package::STATUS_ACTIVE,
                7500.00, ['svc_fiber_200m', 'svc_static_ip', 'svc_voice_line']],
        ];

        foreach ($packages as [$pid, $code, $name, $display, $status, $price, $svcIds]) {
            $package = Package::query()->updateOrCreate(
                ['id' => $pid],
                ['operator_code' => $op, 'code' => $code, 'name' => $name, 'display_name' => $display,
                 'description' => $name.' fibre package', 'status' => $status, 'billing_frequency_days' => 30,
                 'default_tax_group_ref' => 'STANDARD_VAT'],
            );

            // package_service composition links.
            foreach ($svcIds as $seq => $svcId) {
                PackageService::query()->updateOrCreate(
                    ['package_id' => $package->id, 'service_id' => $svcId],
                    ['sequence' => $seq],
                );
            }

            // ACTIVE priced version (deterministic id so it stays idempotent).
            $versionId = 'pkv_'.$pid;
            $version = PackageVersion::query()->updateOrCreate(
                ['id' => $versionId],
                ['package_id' => $package->id, 'price' => $price, 'currency' => 'KES',
                 'effective_from' => now()->subMonth(), 'status' => PackageVersion::STATUS_ACTIVE],
            );

            // Point the package at its active version.
            if ($package->current_version_id !== $version->id || $package->status !== $status) {
                $package->update(['current_version_id' => $version->id, 'status' => $status]);
            }
        }

        // ── SIP-04 commercial bundles ────────────────────────────────────────
        $bundles = [
            ['bun_home_starter', 'HOME_STARTER', 'Home Starter Bundle', 'ACQUISITION', 'pkg_fiber_100m'],
            ['bun_home_pro', 'HOME_PRO', 'Home Pro Bundle', 'GENERAL', 'pkg_fiber_200m'],
        ];
        foreach ($bundles as [$bid, $code, $name, $type, $pkgRef]) {
            $bundle = CommercialBundle::query()->updateOrCreate(
                ['bundle_id' => $bid],
                ['operator_code' => $op, 'bundle_code' => $code, 'display_name' => $name,
                 'description' => $name, 'status' => CommercialBundle::ACTIVE, 'bundle_type' => $type,
                 'currency_code' => 'KES', 'launch_date' => now()->subMonth()->toDateString()],
            );
            BundleComponent::query()->updateOrCreate(
                ['component_id' => 'bcomp_'.$bid],
                ['bundle_id' => $bundle->bundle_id, 'package_ref' => $pkgRef, 'component_role' => 'PRIMARY',
                 'quantity' => 1, 'mandatory' => true, 'display_order' => 0],
            );
        }

        // ── Promo campaign ───────────────────────────────────────────────────
        PromoCampaign::query()->updateOrCreate(
            ['campaign_id' => 'camp_fiber_launch'],
            ['operator_code' => $op, 'code' => 'FIBER_LAUNCH', 'name' => 'Fiber Launch Promo',
             'segment' => 'RESIDENTIAL', 'status' => 'ACTIVE',
             'starts_at' => now()->subWeek(), 'ends_at' => now()->addMonths(3)],
        );
    }
}
