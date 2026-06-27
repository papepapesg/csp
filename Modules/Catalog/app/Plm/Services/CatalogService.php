<?php

namespace Modules\Catalog\Plm\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Network\Models\HomePass;
use Modules\Catalog\Plm\Models\Package;
use Modules\Catalog\Plm\Models\PackageVersion;
use Modules\Catalog\Plm\Models\Service;
use Modules\Catalog\Network\Models\TechRegion;

/**
 * Authoritative writes for catalog & reference data (PLM/SIP/RLM/ILM-CFG-02).
 * Each write commits state + a domain event in one transaction (outbox).
 */
class CatalogService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly \App\Foundation\Approvals\ApprovalService $approvals,
    ) {}

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
            try {
                $homepass = HomePass::query()->create($data);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // R-RLM-CFG-01-H-1: the full structured-address tuple is unique deployment-wide.
                throw DomainException::ruleRejected('HOMEPASS_DUPLICATE_ADDRESS', 'A HomePass already exists at this address.');
            }
            $this->emit(CatalogEvents::HOMEPASS_CREATED, 'HomePass', $homepass->id, [
                'homepassId' => $homepass->id, 'techRegionId' => $homepass->tech_region_id,
            ]);

            return $homepass;
        });
    }

    /**
     * RLM-CFG-01 bulk import — partial-reject by default (a bad row is reported but doesn't block
     * the batch); all-or-nothing aborts on the first failure. Each row goes through createHomePass,
     * so H-1 uniqueness and validation apply per row.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array{imported:int, rejected:int, rowResults:array<int,array<string,mixed>>}
     */
    public function bulkImportHomePasses(array $rows, string $mode = 'partial'): array
    {
        $run = function () use ($rows, $mode) {
            $results = [];
            $imported = 0;
            $rejected = 0;
            foreach ($rows as $i => $row) {
                try {
                    $hp = $this->createHomePass($row);
                    $results[] = ['row' => $i + 1, 'status' => 'created', 'homepassId' => $hp->id];
                    $imported++;
                } catch (DomainException $e) {
                    if ($mode === 'all-or-nothing') {
                        throw $e;
                    }
                    $results[] = ['row' => $i + 1, 'status' => 'rejected', 'error' => $e->errorCode];
                    $rejected++;
                }
            }

            return ['imported' => $imported, 'rejected' => $rejected, 'rowResults' => $results];
        };

        return $mode === 'all-or-nothing' ? DB::transaction($run) : $run();
    }

    /**
     * R-RLM-CFG-01-H-14: correct a HomePass's address fields (allowed at any lifecycle point);
     * every change is audit-logged via HomePassAddressCorrected. R-RLM-CFG-01-H-18: google_place_id
     * is immutable once set. The H-1 uniqueness index is re-evaluated on the update.
     *
     * @param  array<string,mixed>  $fields
     */
    public function correctAddress(HomePass $homepass, array $fields): HomePass
    {
        if (array_key_exists('google_place_id', $fields) && $homepass->google_place_id !== null
            && $fields['google_place_id'] !== $homepass->google_place_id) {
            throw DomainException::ruleRejected('GOOGLE_PLACE_ID_IMMUTABLE', 'google_place_id cannot be changed once set.');
        }

        return DB::transaction(function () use ($homepass, $fields) {
            try {
                $homepass->update($fields);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                throw DomainException::ruleRejected('HOMEPASS_DUPLICATE_ADDRESS', 'A HomePass already exists at this address.');
            }
            $this->emit(CatalogEvents::HOMEPASS_ADDRESS_CORRECTED, 'HomePass', $homepass->id, [
                'homepassId' => $homepass->id, 'changed' => array_keys($fields),
            ]);

            return $homepass->refresh();
        });
    }

    public function changeHomePassStatus(HomePass $homepass, string $status, ?string $actor = null, bool $bypassApproval = false): HomePass
    {
        // RLM-CFG-01 §1: status semantics come from the operator's config catalog, never a
        // hardcoded code. The catalog row's flags (is_sellable / is_active / …) drive behaviour.
        $code = \Modules\Catalog\Network\Models\HomePassStatusCode::resolve($homepass->operator_code, $status);
        $hasCatalog = \Modules\Catalog\Network\Models\HomePassStatusCode::query()->where('operator_code', $homepass->operator_code)->where('active', true)->exists();
        if ($hasCatalog && (! $code || ! $code->active)) {
            throw \App\Foundation\Errors\DomainException::ruleRejected('UNKNOWN_HOMEPASS_STATUS', "Status '{$status}' is not an active HomePass status code.");
        }

        // R-RLM-CFG-01-H-5 maker-checker: a transition INTO a requires_approval_to_enter code is
        // routed through EM-CFG-04. If a policy gates it, the request is PENDING and the status does
        // NOT flip yet — it flips when the approval is granted (see ApplyHomePassTransitionOnApproval).
        if ($code && $code->requires_approval_to_enter && ! $bypassApproval) {
            $req = $this->approvals->request([
                'operator_code' => $homepass->operator_code,
                'entity_type' => 'HOMEPASS_STATUS_TRANSITION',
                'action' => $status,
                'entity_ref' => $homepass->id,
                'payload' => ['targetStatus' => $status],
                'requested_by' => $actor,
            ]);
            if ($req->status === \App\Foundation\Approvals\ApprovalRequest::PENDING) {
                return $homepass; // awaiting approval; status unchanged
            }
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
