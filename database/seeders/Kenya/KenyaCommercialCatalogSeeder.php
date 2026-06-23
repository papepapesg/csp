<?php

namespace Database\Seeders\Kenya;

use App\Foundation\Support\Context;
use Illuminate\Database\Seeder;
use Modules\Catalog\Models\BundleComponent;
use Modules\Catalog\Models\CommercialBundle;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageService;
use Modules\Catalog\Models\PackageVersion;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Models\ServiceClass;

/**
 * Wananchi Kenya (Zuku) commercial catalog — pure CONFIGURATION on the generic
 * SOPHIX platform (PLM-CFG-01 / SIP-01 / SIP-04). No platform code is touched.
 *
 * Models the AS-IS reality from the Confluence understanding:
 *   - three service families → three SOPHIX service classes (Broadband / TV / Voice);
 *   - Zuku Fibre internet tiers, IPTV bouquets and a VoIP line as sellable services;
 *   - single- / double- / triple-play packages priced in KES on a 30-day cycle;
 *   - DUAL-WALLET routing: Internet/TV charges settle on MONEY_KES, Voice usage on
 *     VOICE_KES (the wallet catalog the platform already seeds), so a triple-play
 *     subscriber is billed once per wallet exactly as Broadhub does today.
 *
 * Idempotent (updateOrCreate) — safe to re-run.
 */
class KenyaCommercialCatalogSeeder extends Seeder
{
    private const OP = 'WIK';

    private const INTERNET_WALLET = 'MONEY_KES';   // Internet + TV settlement wallet

    private const VOICE_WALLET = 'VOICE_KES';      // separate Voice usage wallet

    private const TAX_GROUP = 'WIK_INTERNET';      // KRA excise + VAT cascade (platform-seeded)

    public function run(): void
    {
        Context::setOperatorCode(self::OP);

        // ── Service classes (the three Zuku families) ────────────────────────
        $broadband = $this->serviceClass('scls_broadband', 'Broadband', 'Fixed fibre/HFC broadband', true);
        $tv = $this->serviceClass('scls_tv', 'Television', 'Zuku IPTV / pay-TV', true);
        $voice = $this->serviceClass('scls_voice', 'Voice', 'Zuku fixed voice / VoIP', false);

        // ── Services (sellable / provisionable units) ────────────────────────
        // [id, name, code, classId, group, addressable, consumption, revenue, wallet, provisioner]
        $internetTiers = [
            ['svc_zuku_int_10', 'Zuku Fibre 10 Mbps', 'INT_10M', 10],
            ['svc_zuku_int_30', 'Zuku Fibre 30 Mbps', 'INT_30M', 30],
            ['svc_zuku_int_60', 'Zuku Fibre 60 Mbps', 'INT_60M', 60],
            ['svc_zuku_int_100', 'Zuku Fibre 100 Mbps', 'INT_100M', 100],
            ['svc_zuku_int_250', 'Zuku Fibre 250 Mbps', 'INT_250M', 250],
        ];
        foreach ($internetTiers as [$id, $name, $code, $mbps]) {
            $this->service($id, $name, $code, $broadband->id, 'BROADBAND', true, 'FLAT', 'INTERNET',
                self::INTERNET_WALLET, 'PROVISIONER_OLT', ['downstream_mbps' => $mbps, 'upstream_mbps' => max(5, (int) ($mbps / 4))]);
        }
        $this->service('svc_zuku_tv_basic', 'Zuku TV Basic Bouquet', 'TV_BASIC', $tv->id, 'TV', false, 'FLAT', 'TV',
            self::INTERNET_WALLET, 'PROVISIONER_IPTV', ['bouquet' => 'BASIC']);
        $this->service('svc_zuku_tv_plus', 'Zuku TV Plus Bouquet', 'TV_PLUS', $tv->id, 'TV', false, 'FLAT', 'TV',
            self::INTERNET_WALLET, 'PROVISIONER_IPTV', ['bouquet' => 'PLUS']);
        // Voice is metered usage and routes to the dedicated VOICE_KES wallet.
        $this->service('svc_zuku_voice', 'Zuku Voice Line', 'VOICE_LINE', $voice->id, 'VOICE', false, 'USAGE', 'VOICE',
            self::VOICE_WALLET, 'PROVISIONER_VOICE', ['line_type' => 'SIP']);

        // ── Packages (+ ACTIVE priced version + service composition) ─────────
        // Single play — internet only. [pkgId, code, name, priceKES, [serviceIds]]
        $single = [
            ['pkg_zuku_fibre_10', 'ZUKU_FIBRE_10', 'Zuku Fibre 10', 2499.00, ['svc_zuku_int_10']],
            ['pkg_zuku_fibre_30', 'ZUKU_FIBRE_30', 'Zuku Fibre 30', 3499.00, ['svc_zuku_int_30']],
            ['pkg_zuku_fibre_60', 'ZUKU_FIBRE_60', 'Zuku Fibre 60', 4499.00, ['svc_zuku_int_60']],
            ['pkg_zuku_fibre_100', 'ZUKU_FIBRE_100', 'Zuku Fibre 100', 6499.00, ['svc_zuku_int_100']],
            ['pkg_zuku_fibre_250', 'ZUKU_FIBRE_250', 'Zuku Fibre 250', 9999.00, ['svc_zuku_int_250']],
        ];
        // Double play — internet + TV.
        $double = [
            ['pkg_zuku_double_60', 'ZUKU_DOUBLE_60', 'Zuku Double 60 (Net+TV)', 4999.00, ['svc_zuku_int_60', 'svc_zuku_tv_basic']],
            ['pkg_zuku_double_100', 'ZUKU_DOUBLE_100', 'Zuku Double 100 (Net+TV)', 7499.00, ['svc_zuku_int_100', 'svc_zuku_tv_basic']],
        ];
        // Triple play — internet + TV + voice (drives dual-wallet billing).
        $triple = [
            ['pkg_zuku_triple_100', 'ZUKU_TRIPLE_100', 'Zuku Triple 100 (Net+TV+Voice)', 8999.00, ['svc_zuku_int_100', 'svc_zuku_tv_plus', 'svc_zuku_voice']],
        ];
        foreach (array_merge($single, $double, $triple) as [$pid, $code, $name, $price, $svcIds]) {
            $this->package($pid, $code, $name, $price, $svcIds);
        }

        // ── Commercial bundles (single / double / triple play) ───────────────
        $this->bundle('bun_zuku_single', 'ZUKU_SINGLE_PLAY', 'Zuku Single Play', 'ACQUISITION', 'pkg_zuku_fibre_100');
        $this->bundle('bun_zuku_double', 'ZUKU_DOUBLE_PLAY', 'Zuku Double Play', 'GENERAL', 'pkg_zuku_double_100');
        $this->bundle('bun_zuku_triple', 'ZUKU_TRIPLE_PLAY', 'Zuku Triple Play', 'GENERAL', 'pkg_zuku_triple_100');
    }

    private function serviceClass(string $id, string $name, string $desc, bool $equip): ServiceClass
    {
        return ServiceClass::query()->updateOrCreate(
            ['id' => $id],
            ['operator_code' => self::OP, 'name' => $name, 'description' => $desc, 'requires_equipment' => $equip],
        );
    }

    /** @param array<string,mixed> $profile */
    private function service(string $id, string $name, string $code, string $classId, string $group, bool $addr,
        string $consumption, string $revenue, string $wallet, string $provisioner, array $profile): void
    {
        Service::query()->updateOrCreate(
            ['id' => $id],
            ['operator_code' => self::OP, 'name' => $name, 'code' => $code, 'service_class_id' => $classId,
                'service_group' => $group, 'is_addressable' => $addr, 'consumption_model' => $consumption,
                'revenue_category' => $revenue, 'default_wallet_ref' => $wallet, 'default_tax_group_ref' => self::TAX_GROUP,
                'network_profile_shape' => $profile, 'provisioner_key' => $provisioner, 'status' => 'ACTIVE'],
        );
    }

    /** @param list<string> $svcIds */
    private function package(string $pid, string $code, string $name, float $price, array $svcIds): void
    {
        $package = Package::query()->updateOrCreate(
            ['id' => $pid],
            ['operator_code' => self::OP, 'code' => $code, 'name' => $name, 'display_name' => $name,
                'description' => $name.' — Zuku Kenya', 'status' => Package::STATUS_ACTIVE, 'billing_frequency_days' => 30,
                'default_wallet_ref' => self::INTERNET_WALLET, 'default_tax_group_ref' => self::TAX_GROUP],
        );
        foreach ($svcIds as $seq => $svcId) {
            PackageService::query()->updateOrCreate(
                ['package_id' => $package->id, 'service_id' => $svcId],
                ['sequence' => $seq],
            );
        }
        $version = PackageVersion::query()->updateOrCreate(
            ['id' => 'pkv_'.$pid],
            ['package_id' => $package->id, 'price' => $price, 'currency' => 'KES',
                'effective_from' => now()->subMonth(), 'status' => PackageVersion::STATUS_ACTIVE],
        );
        $package->update(['current_version_id' => $version->id, 'status' => Package::STATUS_ACTIVE]);
    }

    private function bundle(string $bid, string $code, string $name, string $type, string $pkgRef): void
    {
        $bundle = CommercialBundle::query()->updateOrCreate(
            ['bundle_id' => $bid],
            ['operator_code' => self::OP, 'bundle_code' => $code, 'display_name' => $name, 'description' => $name,
                'status' => CommercialBundle::ACTIVE, 'bundle_type' => $type, 'currency_code' => 'KES',
                'launch_date' => now()->subMonth()->toDateString()],
        );
        BundleComponent::query()->updateOrCreate(
            ['component_id' => 'bcomp_'.$bid],
            ['bundle_id' => $bundle->bundle_id, 'package_ref' => $pkgRef, 'component_role' => 'PRIMARY',
                'quantity' => 1, 'mandatory' => true, 'display_order' => 0],
        );
    }
}
