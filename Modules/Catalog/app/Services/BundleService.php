<?php

namespace Modules\Catalog\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Models\BundleLaunchCheck;
use Modules\Catalog\Models\BundleMigrationRule;
use Modules\Catalog\Models\CommercialBundle;
use Modules\Catalog\Models\Discount;
use Modules\Catalog\Models\Package;

/**
 * SIP-04 bundle launch orchestration: draft composition, auditable launch checks
 * (R-SIP-BUN-02/03/05), the review→approve→activate lifecycle (§5/§7), channel/
 * region/franchise availability resolution (R-SIP-BUN-10) and migration-path
 * preview (R-SIP-BUN-08/09 — SIP-04 authorizes the path; the SUB workflow executes
 * the customer move).
 */
class BundleService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data with components[], availability[]?, discount_rules[]? */
    public function create(array $data): CommercialBundle
    {
        return DB::transaction(function () use ($data) {
            $bundle = CommercialBundle::query()->create([
                'bundle_code' => $data['bundle_code'],
                'display_name' => $data['display_name'],
                'description' => $data['description'] ?? null,
                'bundle_type' => $data['bundle_type'] ?? 'GENERAL',
                'currency_code' => $data['currency_code'] ?? 'KES',
                'launch_date' => $data['launch_date'] ?? null,
                'retire_date' => $data['retire_date'] ?? null,
                'status' => CommercialBundle::DRAFT,
                'created_by_user_id' => $data['created_by'] ?? null,
            ]);

            foreach ($data['components'] ?? [] as $i => $component) {
                $bundle->components()->create([
                    'package_ref' => $component['package_ref'],
                    'package_version_id' => $component['package_version_id'] ?? null,
                    'component_role' => $component['component_role'] ?? 'PRIMARY',
                    'quantity' => $component['quantity'] ?? 1,
                    'mandatory' => $component['mandatory'] ?? true,
                    'display_order' => $component['display_order'] ?? $i,
                    'metadata_json' => $component['metadata'] ?? null,
                ]);
            }
            foreach ($data['availability'] ?? [] as $row) {
                $bundle->availability()->create($row + ['effective_from' => $row['effective_from'] ?? now()->toDateString()]);
            }
            foreach ($data['discount_rules'] ?? [] as $rule) {
                $bundle->discountRules()->create($rule);
            }

            $this->emit(CatalogEvents::BUNDLE_CREATED, $bundle);

            return $bundle->refresh()->load('components', 'availability', 'discountRules');
        });
    }

    /**
     * §7.2 launch validation — auditable findings persisted as launch checks.
     * R-SIP-BUN-02 (≥1 mandatory component), R-SIP-BUN-03 (package refs active),
     * R-SIP-BUN-05 (discount rules reference active catalog items).
     *
     * @return Collection<int,BundleLaunchCheck>
     */
    public function validate(CommercialBundle $bundle): Collection
    {
        return DB::transaction(function () use ($bundle) {
            $bundle->launchChecks()->delete();
            $checks = [];

            $mandatory = $bundle->components()->where('mandatory', true)->count();
            $checks[] = ['check_code' => 'MANDATORY_COMPONENT',
                'check_status' => $mandatory > 0 ? 'PASS' : 'FAIL',
                'message' => $mandatory > 0 ? "{$mandatory} mandatory component(s)." : 'Bundle has no mandatory package component.'];

            foreach ($bundle->components as $component) {
                $package = Package::query()->where('id', $component->package_ref)
                    ->orWhere(fn ($q) => $q->where('operator_code', $bundle->operator_code)->where('code', $component->package_ref))
                    ->first();
                $ok = $package && $package->status === Package::STATUS_ACTIVE;
                $checks[] = ['check_code' => 'PACKAGE_ACTIVE',
                    'check_status' => $ok ? 'PASS' : 'FAIL',
                    'message' => $ok ? "Package {$component->package_ref} is active." : "Package {$component->package_ref} is missing or not ACTIVE.",
                    'source_ref_json' => ['packageRef' => $component->package_ref]];
            }

            foreach ($bundle->discountRules()->where('status', 'ACTIVE')->get() as $rule) {
                $ok = Discount::query()->where('operator_code', $bundle->operator_code)
                    ->where('code', $rule->discount_code)->where('status', 'ACTIVE')->exists();
                $checks[] = ['check_code' => 'DISCOUNT_ACTIVE',
                    'check_status' => $ok ? 'PASS' : 'FAIL',
                    'message' => $ok ? "Discount {$rule->discount_code} is active." : "Discount {$rule->discount_code} is missing or inactive.",
                    'source_ref_json' => ['discountCode' => $rule->discount_code]];
            }

            foreach ($checks as $check) {
                $bundle->launchChecks()->create($check);
            }
            $this->emit(CatalogEvents::BUNDLE_VALIDATED, $bundle, [
                'failures' => collect($checks)->where('check_status', 'FAIL')->count(),
            ]);

            return $bundle->launchChecks()->get();
        });
    }

    /** §7.3 — allowed only when validation has no FAIL. */
    public function submitForReview(CommercialBundle $bundle): CommercialBundle
    {
        $this->assertStatus($bundle, [CommercialBundle::DRAFT]);
        $failures = $this->validate($bundle)->where('check_status', 'FAIL');
        if ($failures->isNotEmpty()) {
            throw DomainException::ruleRejected('BUNDLE_VALIDATION_FAILED',
                'Launch validation failed: '.$failures->pluck('message')->implode(' '));
        }
        $bundle->update(['status' => CommercialBundle::READY_FOR_REVIEW]);

        return $bundle->refresh();
    }

    public function approve(CommercialBundle $bundle, ?string $comment = null, ?string $actor = null): CommercialBundle
    {
        $this->assertStatus($bundle, [CommercialBundle::READY_FOR_REVIEW]);
        $bundle->update(['status' => CommercialBundle::APPROVED]);
        $this->emit(CatalogEvents::BUNDLE_APPROVED, $bundle, ['comment' => $comment, 'actor' => $actor]);

        return $bundle->refresh();
    }

    /** §7.5 — sellable on/after launch_date. */
    public function activate(CommercialBundle $bundle): CommercialBundle
    {
        $this->assertStatus($bundle, [CommercialBundle::APPROVED]);
        if ($bundle->launch_date && $bundle->launch_date->isFuture()) {
            throw DomainException::conflict("Bundle launches on {$bundle->launch_date->toDateString()}; cannot activate earlier.");
        }
        $bundle->update(['status' => CommercialBundle::ACTIVE]);
        $this->emit(CatalogEvents::BUNDLE_ACTIVATED, $bundle);

        return $bundle->refresh();
    }

    /** R-SIP-BUN-07: retiring blocks new orders; existing subscriptions are untouched. */
    public function retire(CommercialBundle $bundle): CommercialBundle
    {
        $this->assertStatus($bundle, [CommercialBundle::ACTIVE, CommercialBundle::SUSPENDED]);
        $bundle->update(['status' => CommercialBundle::RETIRED, 'retire_date' => $bundle->retire_date ?? now()->toDateString()]);
        $this->emit(CatalogEvents::BUNDLE_RETIRED, $bundle);

        return $bundle->refresh();
    }

    /**
     * §7.6 / R-SIP-BUN-10: the bundles sellable for a channel (+ optional franchise/
     * region) today — what sales/self-care may show.
     *
     * @return Collection<int,CommercialBundle>
     */
    public function available(string $channelCode, ?string $franchiseId = null, ?string $regionCode = null): Collection
    {
        $today = now()->toDateString();

        return CommercialBundle::query()
            ->where('operator_code', Context::operatorCode())
            ->where('status', CommercialBundle::ACTIVE)
            ->whereHas('availability', fn ($q) => $q
                ->where('status', 'ACTIVE')
                ->where('channel_code', $channelCode)
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
                ->when($franchiseId, fn ($q2) => $q2->where(fn ($w) => $w->whereNull('franchise_id')->orWhere('franchise_id', $franchiseId)))
                ->when($regionCode, fn ($q2) => $q2->where(fn ($w) => $w->whereNull('region_code')->orWhere('region_code', $regionCode))))
            ->with(['components', 'discountRules' => fn ($q) => $q->where('status', 'ACTIVE')])
            ->get();
    }

    /**
     * §7.7 migration preview: SIP-04 authorizes the path; SUB workflow executes it
     * (R-SIP-BUN-08/09). @return array<string,mixed>
     */
    public function migrationPreview(string $sourceBundleCode, string $targetBundleCode, string $channelCode): array
    {
        $operator = Context::operatorCode();
        $source = CommercialBundle::query()->where('operator_code', $operator)->where('bundle_code', $sourceBundleCode)->first();
        $target = CommercialBundle::query()->where('operator_code', $operator)->where('bundle_code', $targetBundleCode)->first();
        if (! $source || ! $target) {
            return ['allowed' => false, 'reason' => 'UNKNOWN_BUNDLE'];
        }

        $today = now()->toDateString();
        $rule = BundleMigrationRule::query()
            ->where('source_bundle_id', $source->bundle_id)
            ->where('target_bundle_id', $target->bundle_id)
            ->where('status', 'ACTIVE')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->first();

        if (! $rule) {
            return ['allowed' => false, 'reason' => 'NO_ACTIVE_MIGRATION_RULE']; // R-SIP-BUN-08
        }
        if ($rule->allowed_channel_json && ! in_array($channelCode, $rule->allowed_channel_json, true)) {
            return ['allowed' => false, 'reason' => 'CHANNEL_NOT_ALLOWED'];
        }

        $workflow = in_array($rule->movement_type, ['UPGRADE', 'DOWNGRADE', 'MIGRATION'], true)
            ? 'SUB-WF-'.$rule->movement_type.'-01'
            : 'SUB-WF-MIGRATION-01';

        return [
            'allowed' => true,
            'movementType' => $rule->movement_type,
            'requiresCustomerConsent' => (bool) $rule->requires_customer_consent,
            'requiresWo' => (bool) $rule->requires_wo,
            'feePolicyCode' => $rule->fee_policy_code,
            'owningWorkflow' => $workflow, // R-SIP-BUN-09: execution is delegated
        ];
    }

    private function assertStatus(CommercialBundle $bundle, array $allowed): void
    {
        if (! in_array($bundle->status, $allowed, true)) {
            throw DomainException::conflict("Bundle is {$bundle->status}; expected ".implode('/', $allowed).'.');
        }
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, CommercialBundle $bundle, array $extra = []): void
    {
        $this->events->publish(new DomainEvent(
            type: $type,
            topic: CatalogEvents::TOPIC,
            payload: array_merge(['bundleId' => $bundle->bundle_id, 'bundleCode' => $bundle->bundle_code, 'status' => $bundle->status], $extra),
            aggregateType: 'CommercialBundle',
            aggregateId: $bundle->bundle_id,
        ));
    }
}
