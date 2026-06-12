<?php

namespace Modules\Catalog\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Models\HomePass;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageVersion;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Models\TechRegion;

/**
 * Authoritative writes for catalog & reference data (PLM/SIP/RLM/ILM-CFG-02).
 * Each write commits state + a domain event in one transaction (outbox).
 */
class CatalogService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function createService(array $data): Service
    {
        return DB::transaction(function () use ($data) {
            $service = Service::query()->create($data);
            $this->emit(CatalogEvents::SERVICE_CREATED, 'Service', $service->id, [
                'serviceId' => $service->id, 'code' => $service->code,
            ]);

            return $service;
        });
    }

    /** @param array<string,mixed> $data */
    public function createPackage(array $data): Package
    {
        return DB::transaction(function () use ($data) {
            $package = Package::query()->create($data);
            $this->emit(CatalogEvents::PACKAGE_CREATED, 'Package', $package->id, [
                'packageId' => $package->id, 'code' => $package->code,
            ]);

            return $package;
        });
    }

    /** @param array<string,mixed> $data */
    public function addVersion(Package $package, array $data): PackageVersion
    {
        return DB::transaction(function () use ($package, $data) {
            $version = $package->versions()->create($data);
            $this->emit(CatalogEvents::PACKAGE_VERSION_ADDED, 'Package', $package->id, [
                'packageId' => $package->id, 'versionId' => $version->id, 'price' => (string) $version->price,
            ]);

            return $version;
        });
    }

    /**
     * Activate a package: its current version becomes ACTIVE and the package
     * transitions DRAFT/INACTIVE -> ACTIVE (SIP-01 launch lifecycle, simplified).
     */
    public function activatePackage(Package $package, ?string $versionId = null): Package
    {
        $version = $versionId
            ? $package->versions()->where('id', $versionId)->first()
            : $package->versions()->latest('effective_from')->first();

        if (! $version) {
            throw DomainException::ruleRejected('PACKAGE_NO_VERSION', 'Package has no version to activate.');
        }

        return DB::transaction(function () use ($package, $version) {
            $package->versions()->where('status', PackageVersion::STATUS_ACTIVE)
                ->update(['status' => PackageVersion::STATUS_SUPERSEDED]);
            $version->update(['status' => PackageVersion::STATUS_ACTIVE]);
            $package->update([
                'status' => Package::STATUS_ACTIVE,
                'current_version_id' => $version->id,
            ]);

            $this->emit(CatalogEvents::PACKAGE_ACTIVATED, 'Package', $package->id, [
                'packageId' => $package->id, 'versionId' => $version->id,
            ]);

            return $package->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function createTechRegion(array $data): TechRegion
    {
        return DB::transaction(function () use ($data) {
            $region = TechRegion::query()->create($data);
            $this->emit(CatalogEvents::TECH_REGION_CREATED, 'TechRegion', $region->tech_region_id, [
                'techRegionId' => $region->tech_region_id,
            ]);

            return $region;
        });
    }

    /** @param array<string,mixed> $data */
    public function createHomePass(array $data): HomePass
    {
        return DB::transaction(function () use ($data) {
            $homepass = HomePass::query()->create($data);
            $this->emit(CatalogEvents::HOMEPASS_CREATED, 'HomePass', $homepass->id, [
                'homepassId' => $homepass->id, 'techRegionId' => $homepass->tech_region_id,
            ]);

            return $homepass;
        });
    }

    public function changeHomePassStatus(HomePass $homepass, string $status): HomePass
    {
        // RLM-CFG-01 §1: status semantics come from the operator's config catalog, never a
        // hardcoded code. The catalog row's flags (is_sellable / is_active / …) drive behaviour.
        $code = \Modules\Catalog\Models\HomePassStatusCode::resolve($homepass->operator_code, $status);
        $hasCatalog = \Modules\Catalog\Models\HomePassStatusCode::query()->where('operator_code', $homepass->operator_code)->where('active', true)->exists();
        if ($hasCatalog && (! $code || ! $code->active)) {
            throw \App\Foundation\Errors\DomainException::ruleRejected('UNKNOWN_HOMEPASS_STATUS', "Status '{$status}' is not an active HomePass status code.");
        }

        return DB::transaction(function () use ($homepass, $status, $code) {
            // R-RLM-CFG-01-H-6: the lead-notify event fires only on the FIRST transition into a
            // sellable status (latched via has_been_sellable); re-entry does not re-emit.
            $firstSellable = $code && $code->is_sellable && $code->triggers_lead_notification && ! $homepass->has_been_sellable;

            $homepass->update([
                'status' => $status,
                'has_been_active' => $homepass->has_been_active || (bool) ($code?->is_active),
                'has_been_sellable' => $homepass->has_been_sellable || (bool) ($code?->is_sellable),
            ]);
            $this->emit(CatalogEvents::HOMEPASS_STATUS_CHANGED, 'HomePass', $homepass->id, [
                'homepassId' => $homepass->id, 'status' => $status,
                'isSellable' => (bool) ($code?->is_sellable), 'isActive' => (bool) ($code?->is_active),
            ]);
            if ($firstSellable) {
                $this->emit(CatalogEvents::HOMEPASS_REACHED_SELLABLE, 'HomePass', $homepass->id, [
                    'homepassId' => $homepass->id, 'techRegionId' => $homepass->tech_region_id, 'status' => $status,
                ]);
            }

            return $homepass;
        });
    }

    /** @param array<string,mixed> $payload */
    private function emit(string $type, string $aggregateType, string $aggregateId, array $payload): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: $payload,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
        ));
    }
}
