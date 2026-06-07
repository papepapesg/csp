<?php

namespace Modules\Subscription\Services;

use App\Foundation\Errors\DomainException;
use Illuminate\Support\Str;
use Modules\Subscription\Models\Subscription;
use Modules\Subscription\Models\SubscriptionOperation;
use Modules\Subscription\Models\SubscriptionRestrictConfig;
use Modules\Subscription\Models\SubscriptionRestriction;

/**
 * SUB-WF-RESTRICT-01 — applies / removes partial-service restrictions on an
 * ACTIVE subscription. This is the one operation that does NOT mutate status_code
 * (R-S-2): it only mutates the active_restrictions[] JSONB array, via the
 * config-driven `sub-restrict` flow.
 *
 * Synchronous preconditions (state, catalog, already-restricted / not-applied,
 * self-service / admin / dunning-marker gating) are validated here and surface as
 * the DD's documented 4xx rejections; the happy path then starts the RESTRICT
 * operation (intent carried as a process variable), which performs the array
 * mutation + FUL-04 fulfillment + notification.
 */
class RestrictionService
{
    public const TRIGGER_BACKOFFICE = 'BACKOFFICE_OPERATOR';

    public const TRIGGER_CALL_CENTER = 'CALL_CENTER';

    public const TRIGGER_SELF_SERVICE = 'CUSTOMER_SELF_SERVICE';

    public const TRIGGER_DUNNING = 'DUNNING_DRIVEN';

    public function __construct(private readonly OperationFramework $framework) {}

    /**
     * ADD a restriction. $opts: activationTrigger, actorRole, actorUserId, notes,
     * dunningReference, idempotencyKey.
     *
     * @param  array<string,mixed>  $opts
     */
    public function add(Subscription $subscription, string $code, array $opts = []): SubscriptionOperation
    {
        $trigger = $opts['activationTrigger'] ?? self::TRIGGER_BACKOFFICE;

        $this->assertActive($subscription, $code); // R-S-1
        $catalog = $this->catalogRow($subscription->operator_code, $code); // R-C-1

        // R-I-1: already restricted.
        if ($this->findActive($subscription, $code) !== null) {
            throw new DomainException('ALREADY_RESTRICTED', "Restriction {$code} is already active.", 409);
        }

        $this->assertSelfServiceAllowed($subscription->operator_code, $catalog, $trigger); // R-C-3
        $this->assertAdminAllowed($catalog, $trigger, $opts['actorRole'] ?? null); // R-C-4

        // R-DM-1: dunning-driven adds are system-marked and cannot be caller-overridden.
        $dunningMarker = $trigger === self::TRIGGER_DUNNING;

        return $this->framework->trigger(
            subscription: $subscription,
            kind: 'RESTRICT',
            input: [
                'intent' => 'ADD',
                'restrictionCode' => $code,
                'fulfillmentAction' => $catalog->fulfillment_action,
                'activationTrigger' => $trigger,
                'dunningMarker' => $dunningMarker,
                'dunningReference' => $dunningMarker ? ($opts['dunningReference'] ?? null) : null,
                'notes' => $opts['notes'] ?? null,
                'addedByRole' => $opts['actorRole'] ?? null,
                'actorUserId' => $opts['actorUserId'] ?? null,
            ],
            idempotencyKey: $opts['idempotencyKey'] ?? "restrict-add-{$subscription->subscription_id}-{$code}",
            actorUserId: $opts['actorUserId'] ?? null,
            actorRole: $opts['actorRole'] ?? null,
            exclusive: false, // R-S-3: RESTRICT is non-exclusive
        );
    }

    /**
     * REMOVE a restriction. $opts: actorRole, actorUserId, notes, dunningOverride,
     * activationTrigger, idempotencyKey.
     *
     * @param  array<string,mixed>  $opts
     */
    public function remove(Subscription $subscription, string $code, array $opts = []): SubscriptionOperation
    {
        $trigger = $opts['activationTrigger'] ?? self::TRIGGER_BACKOFFICE;

        $this->assertActive($subscription, $code); // R-S-1
        $catalog = $this->catalogRow($subscription->operator_code, $code, requireActive: false);

        // R-I-2: not currently applied.
        $entry = $this->findActive($subscription, $code);
        if ($entry === null) {
            throw new DomainException('RESTRICTION_NOT_APPLIED', "Restriction {$code} is not applied.", 409);
        }

        $this->assertRemovable($subscription->operator_code, $entry, $opts); // R-DM-2/3/4

        return $this->framework->trigger(
            subscription: $subscription,
            kind: 'RESTRICT',
            input: [
                'intent' => 'REMOVE',
                'restrictionCode' => $code,
                'fulfillmentAction' => $catalog->fulfillment_action,
                'activationTrigger' => $trigger,
                'dunningOverride' => (bool) ($opts['dunningOverride'] ?? false),
                'notes' => $opts['notes'] ?? null,
                'removedByRole' => $opts['actorRole'] ?? null,
                'actorUserId' => $opts['actorUserId'] ?? null,
            ],
            idempotencyKey: $opts['idempotencyKey'] ?? "restrict-remove-{$subscription->subscription_id}-{$code}-".now()->timestamp,
            actorUserId: $opts['actorUserId'] ?? null,
            actorRole: $opts['actorRole'] ?? null,
            exclusive: false, // R-S-3
        );
    }

    /**
     * BIL-04 resume-after-payment: lift all dunning-marked restrictions for a
     * subscription (R-DM-4 — BILLING_INTERNAL actor may always remove).
     *
     * @return list<string> the restriction codes that were lifted
     */
    public function removeDunningMarked(Subscription $subscription, ?string $dunningReference = null): array
    {
        $lifted = [];
        foreach ($subscription->active_restrictions ?? [] as $entry) {
            if (! ($entry['dunningMarker'] ?? false)) {
                continue;
            }
            $code = $entry['restrictionCode'];
            $this->remove($subscription, $code, [
                'activationTrigger' => self::TRIGGER_DUNNING,
                'actorRole' => 'BILLING_INTERNAL',
                'actorUserId' => 'bil04-svc-account',
                'notes' => 'Dunning resume-after-payment',
                'idempotencyKey' => "restrict-remove-{$subscription->subscription_id}-{$code}-".($dunningReference ?? 'resume'),
            ]);
            $lifted[] = $code;
            $subscription->refresh();
        }

        return $lifted;
    }

    /** @return array<int,array<string,mixed>> active restriction entries */
    public function list(Subscription $subscription): array
    {
        return $subscription->active_restrictions ?? [];
    }

    /**
     * Find an active restriction entry by code.
     *
     * @return array<string,mixed>|null
     */
    private function findActive(Subscription $subscription, string $code): ?array
    {
        foreach ($subscription->active_restrictions ?? [] as $entry) {
            if (($entry['restrictionCode'] ?? null) === $code) {
                return $entry;
            }
        }

        return null;
    }

    /** R-S-1: restrictions are only mutated while the subscription is ACTIVE. */
    private function assertActive(Subscription $subscription, string $code): void
    {
        if ($subscription->status_code !== Subscription::ACTIVE) {
            throw new DomainException(
                'INVALID_STATE_FOR_RESTRICTION',
                "Subscription must be ACTIVE to change restrictions (current: {$subscription->status_code}).",
                409,
            );
        }
    }

    private function catalogRow(string $operator, string $code, bool $requireActive = true): SubscriptionRestriction
    {
        $row = SubscriptionRestriction::query()
            ->where('operator_code', $operator)
            ->where('restriction_code', $code)
            ->first();

        if (! $row) {
            throw new DomainException('UNKNOWN_RESTRICTION_CODE', "Unknown restriction code {$code}.", 400);
        }
        if ($requireActive && ! $row->is_active) {
            throw new DomainException('RESTRICTION_CODE_INACTIVE', "Restriction code {$code} is inactive.", 400);
        }

        return $row;
    }

    /** R-C-3: customer self-service requires operator config AND catalog eligibility. */
    private function assertSelfServiceAllowed(string $operator, SubscriptionRestriction $catalog, string $trigger): void
    {
        if ($trigger !== self::TRIGGER_SELF_SERVICE) {
            return;
        }
        $config = SubscriptionRestrictConfig::forOperator($operator);
        if (! $config->customer_self_service_enabled || ! $catalog->customer_self_service_eligible) {
            throw new DomainException('CUSTOMER_SELF_SERVICE_NOT_ALLOWED', "Self-service not allowed for {$catalog->restriction_code}.", 403);
        }
    }

    /** R-C-4: admin_only catalog rows require a SUBSCRIPTION_ADMIN actor. */
    private function assertAdminAllowed(SubscriptionRestriction $catalog, string $trigger, ?string $actorRole): void
    {
        if (! $catalog->admin_only) {
            return;
        }
        if ($trigger === self::TRIGGER_DUNNING) {
            return; // system-driven
        }
        if ($actorRole !== 'SUBSCRIPTION_ADMIN') {
            throw new DomainException('ADMIN_ONLY_RESTRICTION', "Restriction {$catalog->restriction_code} requires an admin actor.", 403);
        }
    }

    /**
     * R-DM-2/3/4: dunning-marked restrictions are protected from human removal.
     *
     * @param  array<string,mixed>  $entry
     * @param  array<string,mixed>  $opts
     */
    private function assertRemovable(string $operator, array $entry, array $opts): void
    {
        if (! ($entry['dunningMarker'] ?? false)) {
            return;
        }
        $role = $opts['actorRole'] ?? null;
        if ($role === 'BILLING_INTERNAL') {
            return; // R-DM-4
        }

        $config = SubscriptionRestrictConfig::forOperator($operator);
        if ($config->dunning_marker_strict) {
            throw new DomainException(
                'SYSTEM_MANAGED_RESTRICTION',
                'This restriction was applied by automated dunning; settle the outstanding balance to remove it.',
                403,
            );
        }

        // R-DM-3: admin may override when not strict, with explicit dunningOverride.
        if ($role !== 'SUBSCRIPTION_ADMIN' || ! ($opts['dunningOverride'] ?? false)) {
            throw new DomainException(
                'SYSTEM_MANAGED_RESTRICTION',
                'Removing a dunning-marked restriction requires an admin override.',
                403,
            );
        }
    }

    /** Build the active_restrictions[] entry persisted on ADD. */
    public static function buildEntry(array $vars, string $actorUserId): array
    {
        return [
            'restrictionCode' => $vars['restrictionCode'],
            'fulfillmentAction' => $vars['fulfillmentAction'] ?? null,
            'addedAt' => now()->toIso8601String(),
            'addedByActor' => $actorUserId,
            'addedByRole' => $vars['addedByRole'] ?? null,
            'addNotes' => $vars['notes'] ?? null,
            'dunningMarker' => (bool) ($vars['dunningMarker'] ?? false),
            'dunningReference' => $vars['dunningReference'] ?? null,
            'activationTrigger' => $vars['activationTrigger'] ?? null,
            'entryId' => (string) Str::ulid(),
        ];
    }
}
