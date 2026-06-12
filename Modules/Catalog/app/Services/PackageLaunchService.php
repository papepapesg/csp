<?php

namespace Modules\Catalog\Services;

use App\Foundation\Approvals\ApprovalRequest;
use App\Foundation\Approvals\ApprovalService;
use App\Foundation\Cache\SophixCache;
use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Rules\RuleEngine;
use App\Foundation\Support\Context;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Models\Package;
use Modules\Catalog\Models\PackageAvailability;
use Modules\Catalog\Models\PackageLaunchCheck;
use Modules\Catalog\Models\PackageLaunchPlan;
use Modules\Catalog\Models\PackageLifecycleEvent;
use Modules\Catalog\Models\PackageRetirementPlan;
use Modules\Catalog\Models\PackageService;
use Modules\Catalog\Models\PackageVersion;
use Modules\Catalog\Models\PackageVersionCutover;
use Modules\Catalog\Models\Service;
use Modules\Catalog\Models\TaxGroup;
use Modules\Catalog\Models\WalletCatalog;
use Modules\Catalog\Support\CatalogCacheKeys;

/**
 * SIP-02 Package Launch Lifecycle orchestration (DD §3-§11). Moves a SIP-01 package
 * version through draft → validated → approved → scheduled → active, governs
 * per-franchise/region/channel availability, end-of-sale and retirement. Approval
 * is delegated to EM-CFG-04 (R-SIP-02-05) — SIP-02 hardcodes no approval steps.
 * Every state change writes an immutable package_lifecycle_event (§5.5) and emits
 * an outbox event whose payload always carries operatorCode (R-SIP-02-12).
 */
class PackageLaunchService
{
    public function __construct(
        private readonly EventBus $events,
        private readonly ApprovalService $approvals,
        private readonly RuleEngine $rules,
        private readonly SophixCache $cache,
    ) {}

    /**
     * 6.1 — create the launch plan in DRAFT plus the requested availability rows
     * (status SCHEDULED). Each availability row is scoped to the plan's version.
     *
     * @param  array<string,mixed>  $data
     */
    public function createPlan(array $data): PackageLaunchPlan
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        return DB::transaction(function () use ($operator, $data) {
            $plan = PackageLaunchPlan::query()->create([
                'operator_code' => $operator,
                'package_id' => $data['packageId'],
                'package_code' => $data['packageCode'] ?? null,
                'package_version_id' => $data['packageVersionId'],
                'launch_type' => $data['launchType'] ?? 'FIRST_LAUNCH',
                'requested_launch_at' => $data['requestedLaunchAt'] ?? null,
                'effective_timezone' => $data['effectiveTimezone'] ?? null,
                'status' => PackageLaunchPlan::STATUS_DRAFT,
                'requested_by_user_id' => $data['requestedByUserId'] ?? null,
            ]);

            foreach ($data['availability'] ?? [] as $row) {
                PackageAvailability::query()->create([
                    'operator_code' => $operator,
                    'package_id' => $plan->package_id,
                    'package_version_id' => $plan->package_version_id,
                    'franchise_id' => $row['franchiseId'] ?? null,
                    'tech_region_code' => $row['techRegionCode'] ?? null,
                    'channel_code' => $row['channelCode'],
                    'available_from' => $row['availableFrom'] ?? $plan->requested_launch_at,
                    'available_until' => $row['availableUntil'] ?? null,
                    'status' => PackageAvailability::STATUS_SCHEDULED,
                    'reason_code' => 'INITIAL_LAUNCH',
                ]);
            }

            $this->recordEvent($plan, null, $plan->status, 'PACKAGE_LAUNCH_PLAN_CREATED', 'PLAN_CREATED');
            $this->emit(CatalogEvents::PACKAGE_LAUNCH_PLAN_CREATED, $plan);

            return $plan->refresh();
        });
    }

    /**
     * 6.2 — run the launch checks and persist them (§5.2). R-01..R-04 are fixed
     * code/DB checks; operator-variable readiness comes from the rule engine
     * (tolerant: an absent decision table is fine). No FAIL → READY_FOR_REVIEW.
     *
     * @return Collection<int,PackageLaunchCheck>
     */
    public function validatePlan(PackageLaunchPlan $plan): Collection
    {
        return DB::transaction(function () use ($plan) {
            $plan->checks()->delete();
            $operator = $plan->operator_code;
            $checks = [];

            // R-SIP-02-01: SIP-01 package + version exist and are launchable.
            $package = Package::query()->where('id', $plan->package_id)->first();
            $version = PackageVersion::query()->where('id', $plan->package_version_id)->first();
            $versionBelongs = $version && $package && $version->package_id === $package->id;
            $launchable = $package
                && in_array($package->status, [Package::STATUS_DRAFT, Package::STATUS_INACTIVE, Package::STATUS_ACTIVE], true);
            $existOk = $package && $version && $versionBelongs;
            $checks[] = [
                'check_code' => 'PACKAGE_VERSION_EXISTS',
                'check_status' => $existOk ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                'message' => $existOk
                    ? 'Package and version exist in SIP-01.'
                    : 'Package or version is missing in SIP-01 or the version does not belong to the package.',
                'source_module_code' => 'SIP-01',
                'source_ref_json' => ['packageId' => $plan->package_id, 'packageVersionId' => $plan->package_version_id],
            ];
            if ($existOk) {
                $checks[] = [
                    'check_code' => 'PACKAGE_LAUNCHABLE',
                    'check_status' => $launchable ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                    'message' => $launchable ? "Package status {$package->status} is launchable." : "Package status {$package->status} is not launchable.",
                    'source_module_code' => 'SIP-01',
                    'source_ref_json' => ['status' => $package->status],
                ];
                $checks[] = [
                    'check_code' => 'VERSION_NOT_SUPERSEDED',
                    'check_status' => $version->status !== PackageVersion::STATUS_SUPERSEDED ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                    'message' => $version->status !== PackageVersion::STATUS_SUPERSEDED ? 'Version is not superseded.' : 'Version has been superseded.',
                    'source_module_code' => 'SIP-01',
                    'source_ref_json' => ['versionStatus' => $version->status],
                ];
            }

            // R-SIP-02-02: package composition services active in PLM-CFG-01.
            $serviceIds = PackageService::query()->where('package_id', $plan->package_id)->pluck('service_id')->all();
            $inactive = Service::query()->whereIn('id', $serviceIds)->where('status', '!=', 'ACTIVE')->pluck('code')->all();
            $servicesOk = $inactive === [];
            $checks[] = [
                'check_code' => 'SERVICE_ACTIVE',
                'check_status' => $servicesOk ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                'message' => $servicesOk
                    ? 'All package services are active.'
                    : 'Inactive services in package composition: '.implode(', ', $inactive).'.',
                'source_module_code' => 'PLM-CFG-01',
                'source_ref_json' => ['serviceIds' => $serviceIds, 'inactive' => $inactive],
            ];

            // R-SIP-02-03: tax group (PLM-CFG-02) and wallet (PLM-CFG-03) valid.
            if ($package) {
                $taxRef = $package->default_tax_group_ref;
                $taxOk = $taxRef === null || TaxGroup::query()->where('operator_code', $operator)->where('code', $taxRef)->exists();
                $checks[] = [
                    'check_code' => 'TAX_GROUP_VALID',
                    'check_status' => $taxOk ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                    'message' => $taxOk ? 'Tax group binding is valid.' : "Tax group {$taxRef} is not a valid PLM-CFG-02 group.",
                    'source_module_code' => 'PLM-CFG-02',
                    'source_ref_json' => ['taxGroupRef' => $taxRef],
                ];

                $walletRef = $package->default_wallet_ref;
                $walletOk = $walletRef === null || WalletCatalog::query()
                    ->where('operator_code', $operator)->where('code', $walletRef)
                    ->where('status', WalletCatalog::STATUS_ACTIVE)->exists();
                $checks[] = [
                    'check_code' => 'WALLET_VALID',
                    'check_status' => $walletOk ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                    'message' => $walletOk ? 'Wallet configuration is valid.' : "Wallet {$walletRef} is not an ACTIVE PLM-CFG-03 wallet.",
                    'source_module_code' => 'PLM-CFG-03',
                    'source_ref_json' => ['walletRef' => $walletRef],
                ];
            }

            // R-SIP-02-04: availability scope inside version/package target franchises/regions.
            $targetFranchises = ($version->target_franchises ?? null) ?: ($package->target_franchises ?? null);
            $targetRegions = ($version->target_tech_regions ?? null) ?: ($package->target_tech_regions ?? null);
            foreach ($plan->availabilityRows()->get() as $row) {
                $franchiseOk = empty($targetFranchises) || $row->franchise_id === null || in_array($row->franchise_id, $targetFranchises, true);
                $regionOk = empty($targetRegions) || $row->tech_region_code === null || in_array($row->tech_region_code, $targetRegions, true);
                $scopeOk = $franchiseOk && $regionOk;
                $checks[] = [
                    'check_code' => 'AVAILABILITY_SCOPE',
                    'check_status' => $scopeOk ? PackageLaunchCheck::STATUS_PASS : PackageLaunchCheck::STATUS_FAIL,
                    'message' => $scopeOk
                        ? "Availability scope {$row->franchise_id}/{$row->tech_region_code} is within target."
                        : "Availability scope {$row->franchise_id}/{$row->tech_region_code} is outside the package/version target.",
                    'source_module_code' => 'SIP-02',
                    'source_ref_json' => ['availabilityId' => $row->availability_id, 'franchiseId' => $row->franchise_id, 'techRegionCode' => $row->tech_region_code],
                ];
            }

            // Operator-variable readiness: rule engine decision table (tolerant — absence is fine).
            foreach ($this->readinessChecksFromRules($plan, $package, $version) as $extra) {
                $checks[] = $extra;
            }

            foreach ($checks as $c) {
                $plan->checks()->create($c + ['checked_at' => now()]);
            }

            $failures = collect($checks)->where('check_status', PackageLaunchCheck::STATUS_FAIL);
            if ($failures->isEmpty() && $plan->status === PackageLaunchPlan::STATUS_DRAFT) {
                $this->transition($plan, PackageLaunchPlan::STATUS_READY_FOR_REVIEW, 'PACKAGE_LAUNCH_VALIDATED', 'VALIDATION_PASSED');
            }
            $this->emit(CatalogEvents::PACKAGE_LAUNCH_VALIDATED, $plan, ['failures' => $failures->count()]);

            return $plan->checks()->get();
        });
    }

    /**
     * 6.3 — submit for approval. Requires READY_FOR_REVIEW. EM-CFG-04 decides
     * (R-SIP-02-05): PENDING → PENDING_APPROVAL; AUTO_APPROVED/APPROVED → applied.
     *
     * @param  array<string,mixed>  $data
     */
    public function submitReview(PackageLaunchPlan $plan, array $data): PackageLaunchPlan
    {
        $this->assertStatus($plan, [PackageLaunchPlan::STATUS_READY_FOR_REVIEW]);

        $request = $this->approvals->request([
            'operator_code' => $plan->operator_code,
            'entity_type' => 'PACKAGE_LAUNCH_PLAN',
            'action' => 'PACKAGE_LAUNCH_APPROVAL',
            'entity_ref' => $plan->launch_plan_id,
            'payload' => [
                'launchType' => $plan->launch_type,
                'packageCode' => $plan->package_code,
                'requestedLaunchAt' => optional($plan->requested_launch_at)->toAtomString(),
            ],
            'requested_by' => $data['requesterUserId'] ?? $plan->requested_by_user_id,
        ]);

        $plan->update([
            'approval_request_id' => $request->request_id,
            'approval_policy_code' => 'PACKAGE_LAUNCH_APPROVAL',
            'approval_mode' => $request->status === ApprovalRequest::PENDING ? 'MULTI_STEP' : 'AUTO',
            'requested_by_user_id' => $data['requesterUserId'] ?? $plan->requested_by_user_id,
        ]);

        if ($request->status === ApprovalRequest::PENDING) {
            $this->transition($plan, PackageLaunchPlan::STATUS_PENDING_APPROVAL, null, 'APPROVAL_REQUESTED');
            $this->emit(CatalogEvents::PACKAGE_LAUNCH_APPROVAL_REQUIRED, $plan);

            return $plan->refresh();
        }

        // AUTO_APPROVED or already APPROVED (no policy / under threshold).
        $this->applyApproved($plan);
        $this->emit(CatalogEvents::PACKAGE_LAUNCH_APPROVED, $plan);

        return $plan->refresh();
    }

    /**
     * 6.4 — EM-CFG-04 approval callback. APPROVED → applyApproved; REJECTED →
     * REJECTED. Idempotent: only acts while the plan is PENDING_APPROVAL.
     */
    public function applyApprovalOutcome(PackageLaunchPlan $plan, string $outcome, ?string $actor = null): PackageLaunchPlan
    {
        if ($plan->status !== PackageLaunchPlan::STATUS_PENDING_APPROVAL) {
            return $plan;
        }
        if (strtoupper($outcome) === 'APPROVED') {
            $plan->update(['approved_by_user_id' => $actor]);
            $this->applyApproved($plan);
            $this->emit(CatalogEvents::PACKAGE_LAUNCH_APPROVED, $plan);
        } else {
            $this->transition($plan, PackageLaunchPlan::STATUS_REJECTED, 'PACKAGE_LAUNCH_REJECTED', 'APPROVAL_REJECTED', $actor);
            $this->emit(CatalogEvents::PACKAGE_LAUNCH_REJECTED, $plan);
        }

        return $plan->refresh();
    }

    /**
     * 6.5 — manual activation. R-SIP-02-07 re-runs final validation (no FAIL),
     * R-SIP-02-05/A requires APPROVED/SCHEDULED, requested launch time is now/past,
     * version not superseded. Then performs the version cutover and makes the plan's
     * availability ACTIVE (R-SIP-02-08: new sales only).
     */
    public function activate(PackageLaunchPlan $plan): PackageLaunchPlan
    {
        $this->assertStatus($plan, [PackageLaunchPlan::STATUS_APPROVED, PackageLaunchPlan::STATUS_SCHEDULED]);

        if ($plan->requested_launch_at && $plan->requested_launch_at->isFuture()) {
            throw DomainException::conflict("Launch is scheduled for {$plan->requested_launch_at->toAtomString()}; cannot activate earlier.");
        }

        $version = PackageVersion::query()->where('id', $plan->package_version_id)->first();
        if (! $version) {
            throw DomainException::notFound('Package version no longer exists.');
        }
        if ($version->status === PackageVersion::STATUS_SUPERSEDED) {
            throw DomainException::conflict('Package version has been superseded and cannot be activated.');
        }

        // R-SIP-02-07: re-run final validation immediately before the state change.
        $failures = $this->validatePlan($plan)->where('check_status', PackageLaunchCheck::STATUS_FAIL);
        if ($failures->isNotEmpty()) {
            $this->recordEvent($plan, $plan->status, $plan->status, 'PACKAGE_ACTIVATION_BLOCKED', 'FINAL_VALIDATION_FAILED', [
                'failures' => $failures->pluck('message')->values()->all(),
            ]);
            throw DomainException::ruleRejected('PACKAGE_ACTIVATION_BLOCKED',
                'Final validation failed: '.$failures->pluck('message')->implode(' '));
        }
        // validatePlan may have flipped DRAFT→READY_FOR_REVIEW; reload the real status.
        $plan->refresh();

        return DB::transaction(function () use ($plan, $version) {
            $package = Package::query()->where('id', $plan->package_id)->first();

            // R-SIP-02-08: supersede other ACTIVE versions, make this one ACTIVE, point
            // current_version_id at it (new sales only). Record the cutover.
            $previousActive = PackageVersion::query()
                ->where('package_id', $plan->package_id)
                ->where('status', PackageVersion::STATUS_ACTIVE)
                ->where('id', '!=', $version->id)
                ->get();
            $fromVersionId = $package?->current_version_id ?: $previousActive->first()?->id;
            foreach ($previousActive as $old) {
                $old->update(['status' => PackageVersion::STATUS_SUPERSEDED]);
            }
            $version->update(['status' => PackageVersion::STATUS_ACTIVE]);

            if ($package) {
                $package->update([
                    'current_version_id' => $version->id,
                    'status' => Package::STATUS_ACTIVE,
                ]);
            }

            $cutover = PackageVersionCutover::query()->create([
                'operator_code' => $plan->operator_code,
                'package_id' => $plan->package_id,
                'from_version_id' => $fromVersionId,
                'to_version_id' => $version->id,
                'cutover_at' => now(),
                'status' => PackageVersionCutover::STATUS_COMPLETED,
                'launch_plan_id' => $plan->launch_plan_id,
                'completed_at' => now(),
            ]);

            // This plan's availability rows become sellable.
            $availabilityIds = $plan->availabilityRows()
                ->whereIn('status', [PackageAvailability::STATUS_SCHEDULED, PackageAvailability::STATUS_SUSPENDED])
                ->pluck('availability_id')->all();
            $plan->availabilityRows()
                ->whereIn('status', [PackageAvailability::STATUS_SCHEDULED, PackageAvailability::STATUS_SUSPENDED])
                ->update(['status' => PackageAvailability::STATUS_ACTIVE, 'reason_code' => 'INITIAL_LAUNCH', 'updated_at' => now()]);

            $plan->update(['status' => PackageLaunchPlan::STATUS_ACTIVE, 'activated_at' => now()]);
            $this->recordEvent($plan, PackageLaunchPlan::STATUS_APPROVED, PackageLaunchPlan::STATUS_ACTIVE, 'PACKAGE_ACTIVATED', 'LAUNCH_REACHED', [
                'cutoverId' => $cutover->cutover_id,
                'availabilityIds' => $availabilityIds,
                'fromVersionId' => $fromVersionId,
                'toVersionId' => $version->id,
            ]);
            $this->emit(CatalogEvents::PACKAGE_ACTIVATED, $plan, [
                'packageVersionId' => $version->id,
                'cutoverId' => $cutover->cutover_id,
                'availabilityIds' => $availabilityIds,
            ]);

            return $plan->refresh();
        });
    }

    /** §11 worker — activate SCHEDULED plans whose requested_launch_at is due. Returns count. */
    public function activateDuePlans(string $operator): int
    {
        $due = PackageLaunchPlan::query()
            ->where('operator_code', $operator)
            ->where('status', PackageLaunchPlan::STATUS_SCHEDULED)
            ->where(fn ($q) => $q->whereNull('requested_launch_at')->orWhere('requested_launch_at', '<=', now()))
            ->get();

        $count = 0;
        foreach ($due as $plan) {
            try {
                $this->activate($plan);
                $count++;
            } catch (DomainException) {
                // A blocked plan stays SCHEDULED; the event/alert is recorded by activate().
            }
        }

        return $count;
    }

    /**
     * 6.6 — the sellable read model. Cache-aside over a plain-array operator snapshot
     * (R-SIP-02-12 keeps it fresh via lifecycle events); the snapshot is then filtered
     * in PHP by channel/franchise/region/date and shaped per DD §6.6.
     *
     * @param  array<string,mixed>  $filters
     * @return array<int,array<string,mixed>>
     */
    public function availablePackages(array $filters): array
    {
        $operator = $filters['operatorCode'] ?? Context::operatorCode();
        [$module, $aggregate, $id] = CatalogCacheKeys::availablePackages($operator);
        $snapshot = $this->cache->remember(
            $module, $aggregate, $id, CatalogCacheKeys::availablePackagesTtl(),
            fn () => $this->buildAvailableSnapshot($operator),
        );

        $channel = $filters['channelCode'] ?? null;
        $franchise = $filters['franchiseId'] ?? null;
        $region = $filters['techRegionCode'] ?? null;
        $onDate = isset($filters['onDate']) ? \Illuminate\Support\Carbon::parse($filters['onDate']) : now();

        $matched = [];
        foreach ($snapshot as $row) {
            $rows = array_filter($row['availability'], function (array $a) use ($channel, $franchise, $region, $onDate) {
                if ($channel !== null && $a['channelCode'] !== $channel) {
                    return false;
                }
                if ($franchise !== null && $a['franchiseId'] !== null && $a['franchiseId'] !== $franchise) {
                    return false;
                }
                if ($region !== null && $a['techRegionCode'] !== null && $a['techRegionCode'] !== $region) {
                    return false;
                }
                if ($a['availableFrom'] !== null && $onDate->lt(\Illuminate\Support\Carbon::parse($a['availableFrom']))) {
                    return false;
                }
                if ($a['availableUntil'] !== null && $onDate->gt(\Illuminate\Support\Carbon::parse($a['availableUntil']))) {
                    return false;
                }

                return true;
            });
            if ($rows === []) {
                continue;
            }
            $channels = array_values(array_unique(array_map(static fn (array $a) => $a['channelCode'], $rows)));
            $matched[] = [
                'packageId' => $row['packageId'],
                'packageCode' => $row['packageCode'],
                'packageVersionId' => $row['packageVersionId'],
                'displayName' => $row['displayName'],
                'price' => $row['price'],
                'currency' => $row['currency'],
                'billingFrequencyDays' => $row['billingFrequencyDays'],
                'availableChannels' => $channels,
            ];
        }

        return $matched;
    }

    /**
     * 6.7 — suspend matching availability rows (R-SIP-02-11: localized control, master
     * package status unchanged).
     *
     * @param  array<string,mixed>  $scope
     */
    public function suspendAvailability(Package $package, array $scope): int
    {
        return $this->flipAvailability($package, $scope, PackageAvailability::STATUS_SUSPENDED,
            $scope['reasonCode'] ?? 'AVAILABILITY_SUSPENDED', 'PACKAGE_AVAILABILITY_SUSPENDED');
    }

    /** 6.8 — resume suspended availability rows. @param array<string,mixed> $scope */
    public function resumeAvailability(Package $package, array $scope): int
    {
        return $this->flipAvailability($package, $scope, PackageAvailability::STATUS_ACTIVE,
            $scope['reasonCode'] ?? 'AVAILABILITY_RESUMED', 'PACKAGE_AVAILABILITY_RESUMED');
    }

    /**
     * 6.9 — create a retirement plan (§5.6). END_OF_SALE blocks new sales without
     * terminating subscriptions (R-SIP-02-09); END_OF_LIFE archives the package.
     * MIGRATE_REQUIRED demands a migration workflow ref (R-SIP-02-10).
     *
     * @param  array<string,mixed>  $data
     */
    public function createRetirement(array $data): PackageRetirementPlan
    {
        $operator = $data['operatorCode'] ?? Context::operatorCode();
        Context::setOperatorCode($operator);

        $policy = $data['existingSubscriberPolicy'] ?? PackageRetirementPlan::POLICY_KEEP_AS_IS;
        if ($policy === PackageRetirementPlan::POLICY_MIGRATE_REQUIRED && empty($data['migrationWorkflowRef'])) {
            // R-SIP-02-10.
            throw DomainException::ruleRejected('MIGRATION_WORKFLOW_REQUIRED',
                'existingSubscriberPolicy MIGRATE_REQUIRED requires a migrationWorkflowRef (SUB/FUL workflow).');
        }

        return DB::transaction(function () use ($operator, $data, $policy) {
            $type = $data['retirementType'];
            $plan = PackageRetirementPlan::query()->create([
                'operator_code' => $operator,
                'package_id' => $data['packageId'],
                'package_version_id' => $data['packageVersionId'] ?? null,
                'retirement_type' => $type,
                'effective_at' => $data['effectiveAt'] ?? now(),
                'existing_subscriber_policy' => $policy,
                'migration_workflow_ref' => $data['migrationWorkflowRef'] ?? null,
                'status' => PackageRetirementPlan::STATUS_SCHEDULED,
                'reason_code' => $data['reasonCode'] ?? null,
                'created_by_user_id' => $data['createdByUserId'] ?? null,
            ]);

            $package = Package::query()->where('id', $data['packageId'])->first();

            if ($type === PackageRetirementPlan::TYPE_END_OF_SALE) {
                // R-SIP-02-09: stop new sales; do NOT touch subscriptions. END_OF_SALE is a
                // SIP-02 lifecycle status on the master package (the SIP-01 enum is a free string,
                // the SIP-02 lifecycle extends it per DD §4).
                $oldStatus = $package?->status;
                $package?->update(['status' => PackageLaunchPlan::STATUS_END_OF_SALE]);
                PackageAvailability::query()
                    ->where('operator_code', $operator)->where('package_id', $data['packageId'])
                    ->whereIn('status', [PackageAvailability::STATUS_ACTIVE, PackageAvailability::STATUS_SCHEDULED, PackageAvailability::STATUS_SUSPENDED])
                    ->update(['status' => PackageAvailability::STATUS_ENDED, 'reason_code' => $data['reasonCode'] ?? 'END_OF_SALE', 'updated_at' => now()]);

                $this->recordPackageEvent($operator, $data['packageId'], $data['packageVersionId'] ?? null, $oldStatus, PackageLaunchPlan::STATUS_END_OF_SALE,
                    'PACKAGE_END_OF_SALE', $data['reasonCode'] ?? 'END_OF_SALE', ['retirementPlanId' => $plan->retirement_plan_id]);
                $this->emitRetirement(CatalogEvents::PACKAGE_END_OF_SALE, $plan);
            } elseif ($type === PackageRetirementPlan::TYPE_END_OF_LIFE) {
                $oldStatus = $package?->status;
                $package?->update(['status' => Package::STATUS_END_OF_LIFE, 'retired_at' => now()]);
                PackageAvailability::query()
                    ->where('operator_code', $operator)->where('package_id', $data['packageId'])
                    ->whereIn('status', [PackageAvailability::STATUS_ACTIVE, PackageAvailability::STATUS_SCHEDULED, PackageAvailability::STATUS_SUSPENDED])
                    ->update(['status' => PackageAvailability::STATUS_ENDED, 'reason_code' => $data['reasonCode'] ?? 'END_OF_LIFE', 'updated_at' => now()]);

                $this->recordPackageEvent($operator, $data['packageId'], $data['packageVersionId'] ?? null, $oldStatus, Package::STATUS_END_OF_LIFE,
                    'PACKAGE_RETIRED', $data['reasonCode'] ?? 'END_OF_LIFE', ['retirementPlanId' => $plan->retirement_plan_id]);
                $this->emitRetirement(CatalogEvents::PACKAGE_RETIRED, $plan);
            } else {
                // RETIRE_VERSION — record the plan; the version supersede is handled by cutover.
                $this->recordPackageEvent($operator, $data['packageId'], $data['packageVersionId'] ?? null, null, $type,
                    'PACKAGE_RETIRE_VERSION', $data['reasonCode'] ?? 'RETIRE_VERSION', ['retirementPlanId' => $plan->retirement_plan_id]);
            }

            return $plan->refresh();
        });
    }

    // --- internals -----------------------------------------------------------

    /**
     * Build the plain-array sellable snapshot for one operator: ACTIVE availability
     * joined to its package + the active version. NOT Eloquent models — this is the
     * cached value.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildAvailableSnapshot(string $operator): array
    {
        $rows = PackageAvailability::query()
            ->where('operator_code', $operator)
            ->where('status', PackageAvailability::STATUS_ACTIVE)
            ->get()
            ->groupBy(fn (PackageAvailability $a) => $a->package_id.'|'.$a->package_version_id);

        $snapshot = [];
        foreach ($rows as $key => $group) {
            [$packageId, $versionId] = explode('|', $key, 2);
            $package = Package::query()->where('id', $packageId)->first();
            $version = PackageVersion::query()->where('id', $versionId)->first();
            if (! $package || ! $version || $version->status !== PackageVersion::STATUS_ACTIVE) {
                continue;
            }
            $snapshot[] = [
                'packageId' => $package->id,
                'packageCode' => $package->code,
                'packageVersionId' => $version->id,
                'displayName' => $package->display_name ?? $package->name,
                'price' => (float) $version->price,
                'currency' => $version->currency,
                'billingFrequencyDays' => (int) $package->billing_frequency_days,
                'availability' => $group->map(fn (PackageAvailability $a) => [
                    'channelCode' => $a->channel_code,
                    'franchiseId' => $a->franchise_id,
                    'techRegionCode' => $a->tech_region_code,
                    'availableFrom' => optional($a->available_from)->toAtomString(),
                    'availableUntil' => optional($a->available_until)->toAtomString(),
                ])->values()->all(),
            ];
        }

        return $snapshot;
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function flipAvailability(Package $package, array $scope, string $status, string $reason, string $eventType): int
    {
        $operator = $package->operator_code;
        $from = $status === PackageAvailability::STATUS_SUSPENDED
            ? [PackageAvailability::STATUS_ACTIVE]
            : [PackageAvailability::STATUS_SUSPENDED];

        return DB::transaction(function () use ($package, $scope, $status, $reason, $eventType, $operator, $from) {
            $query = PackageAvailability::query()
                ->where('operator_code', $operator)
                ->where('package_id', $package->id)
                ->whereIn('status', $from)
                ->when($scope['packageVersionId'] ?? null, fn ($q, $v) => $q->where('package_version_id', $v))
                ->when($scope['franchiseId'] ?? null, fn ($q, $v) => $q->where('franchise_id', $v))
                ->when($scope['techRegionCode'] ?? null, fn ($q, $v) => $q->where('tech_region_code', $v))
                ->when($scope['channelCode'] ?? null, fn ($q, $v) => $q->where('channel_code', $v));

            $ids = $query->pluck('availability_id')->all();
            $affected = $query->update(['status' => $status, 'reason_code' => $reason, 'updated_at' => now()]);

            // R-SIP-02-11: localized availability change does NOT change master status.
            $this->recordPackageEvent($operator, $package->id, $scope['packageVersionId'] ?? null, $package->status, $package->status,
                $eventType, $reason, ['availabilityIds' => $ids, 'scope' => $scope, 'newAvailabilityStatus' => $status]);
            $this->emitAvailability($package, $status, $reason, $ids);

            return $affected;
        });
    }

    /**
     * R-SIP-02-08: APPROVED, then SCHEDULED when the requested launch is in the future
     * (else it stays APPROVED, ready for manual activation).
     */
    private function applyApproved(PackageLaunchPlan $plan): void
    {
        $this->transition($plan, PackageLaunchPlan::STATUS_APPROVED, null, 'APPROVAL_GRANTED');
        if ($plan->requested_launch_at && $plan->requested_launch_at->isFuture()) {
            $this->transition($plan, PackageLaunchPlan::STATUS_SCHEDULED, null, 'LAUNCH_SCHEDULED');
        }
    }

    /**
     * Operator-variable readiness from the rule engine decision table. Tolerant: if
     * the table does not exist (or the engine raises), no extra checks are added.
     *
     * @return array<int,array<string,mixed>>
     */
    private function readinessChecksFromRules(PackageLaunchPlan $plan, ?Package $package, ?PackageVersion $version): array
    {
        try {
            $assessed = $this->rules->assess('rules.package-launch-readiness', [
                'launchType' => $plan->launch_type,
                'packageCode' => $plan->package_code,
                'packageStatus' => $package?->status,
                'versionStatus' => $version?->status,
                'channelCodes' => $plan->availabilityRows()->pluck('channel_code')->unique()->values()->all(),
            ]);
        } catch (\Throwable) {
            return [];
        }

        $checks = [];
        foreach ($assessed['validationErrors'] ?? [] as $e) {
            $checks[] = [
                'check_code' => $e['ruleId'] ?? 'LAUNCH_READINESS',
                'check_status' => PackageLaunchCheck::STATUS_FAIL,
                'message' => $e['message'] ?? 'Launch readiness rule failed.',
                'source_module_code' => 'RULES',
                'source_ref_json' => ['ruleId' => $e['ruleId'] ?? null],
            ];
        }
        foreach ($assessed['decision']['warnings'] ?? [] as $w) {
            $checks[] = [
                'check_code' => 'LAUNCH_READINESS_WARN',
                'check_status' => PackageLaunchCheck::STATUS_WARN,
                'message' => is_array($w) ? ($w['message'] ?? 'Warning.') : (string) $w,
                'source_module_code' => 'RULES',
                'source_ref_json' => null,
            ];
        }

        return $checks;
    }

    /** @param array<int,string> $allowed */
    private function assertStatus(PackageLaunchPlan $plan, array $allowed): void
    {
        if (! in_array($plan->status, $allowed, true)) {
            throw DomainException::conflict("Launch plan is {$plan->status}; expected ".implode('/', $allowed).'.');
        }
    }

    private function transition(PackageLaunchPlan $plan, string $to, ?string $eventType, string $reason, ?string $actor = null): void
    {
        $from = $plan->status;
        $plan->update(['status' => $to]);
        $this->recordEvent($plan, $from, $to, $eventType ?? 'PACKAGE_LAUNCH_STATUS_CHANGED', $reason, [], $actor);
    }

    /** @param array<string,mixed> $metadata */
    private function recordEvent(PackageLaunchPlan $plan, ?string $old, string $new, string $eventType, string $reason, array $metadata = [], ?string $actor = null): void
    {
        $this->recordPackageEvent(
            $plan->operator_code, $plan->package_id, $plan->package_version_id, $old, $new, $eventType, $reason,
            $metadata + ['launchPlanId' => $plan->launch_plan_id], $actor ?? $plan->requested_by_user_id, $plan->launch_plan_id,
        );
    }

    /** @param array<string,mixed> $metadata */
    private function recordPackageEvent(string $operator, string $packageId, ?string $versionId, ?string $old, string $new, string $eventType, string $reason, array $metadata = [], ?string $actor = null, ?string $launchPlanId = null): void
    {
        PackageLifecycleEvent::query()->create([
            'operator_code' => $operator,
            'package_id' => $packageId,
            'package_version_id' => $versionId,
            'launch_plan_id' => $launchPlanId,
            'old_status' => $old,
            'new_status' => $new,
            'event_type' => $eventType,
            'reason_code' => $reason,
            'actor_user_id' => $actor,
            'source_module_code' => 'SIP-02',
            'metadata_json' => $metadata ?: null,
            'created_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, PackageLaunchPlan $plan, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: [
                'launchPlanId' => $plan->launch_plan_id,
                'packageId' => $plan->package_id,
                'packageCode' => $plan->package_code,
                'packageVersionId' => $plan->package_version_id,
                'status' => $plan->status,
                'operatorCode' => $plan->operator_code,
            ] + $extra,
            aggregateType: 'PackageLaunchPlan',
            aggregateId: $plan->launch_plan_id,
        ));
    }

    /** @param array<int,string> $availabilityIds */
    private function emitAvailability(Package $package, string $status, string $reason, array $availabilityIds): void
    {
        $this->events->publish(new DomainEvent(
            type: CatalogEvents::PACKAGE_AVAILABILITY_CHANGED,
            topic: CatalogEvents::TOPIC,
            payload: [
                'packageId' => $package->id,
                'availabilityStatus' => $status,
                'reasonCode' => $reason,
                'availabilityIds' => $availabilityIds,
                'status' => $package->status,
                'operatorCode' => $package->operator_code,
            ],
            aggregateType: 'Package',
            aggregateId: $package->id,
        ));
    }

    private function emitRetirement(string $type, PackageRetirementPlan $plan): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: [
                'retirementPlanId' => $plan->retirement_plan_id,
                'packageId' => $plan->package_id,
                'packageVersionId' => $plan->package_version_id,
                'retirementType' => $plan->retirement_type,
                'status' => $plan->status,
                'operatorCode' => $plan->operator_code,
            ],
            aggregateType: 'PackageRetirementPlan',
            aggregateId: $plan->retirement_plan_id,
        ));
    }
}
