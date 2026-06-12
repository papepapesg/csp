<?php

namespace Modules\Rbac\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use Modules\Rbac\Events\RbacEvents;
use Modules\Rbac\Models\RbacChangeAudit;
use Modules\Rbac\Models\RbacPermissionMeta;
use Modules\Rbac\Models\RbacUserScope;

/**
 * EM-CFG-03 user scope management + enforcement (DD §8.5). Spatie answers "what can this role
 * do"; scopes answer "where". A permission flagged scope_required must, server-side, have the
 * target entity fall within one of the user's active scopes (a GLOBAL scope, an OPERATOR scope
 * for the tenant, or an exact scope_type+value match). This service is the boundary modules call.
 */
class RbacScopeService
{
    public function __construct(private readonly EventBus $events) {}

    /** @param array<string,mixed> $data */
    public function assign(string $authUserId, array $data, ?string $actor = null): RbacUserScope
    {
        $scope = RbacUserScope::query()->updateOrCreate(
            ['auth_user_id' => $authUserId, 'scope_type' => $data['scopeType'], 'scope_value' => $data['scopeValue']],
            [
                'operator_code' => $data['operatorCode'] ?? Context::operatorCode(),
                'scope_label' => $data['scopeLabel'] ?? null,
                'effective_from' => $data['effectiveFrom'] ?? now(),
                'effective_to' => $data['effectiveTo'] ?? null,
                'active' => true, 'created_by_user_id' => $actor,
            ],
        );
        RbacChangeAudit::record('USER_SCOPE_ASSIGNED', 'USER_SCOPE', $authUserId, null,
            ['scopeType' => $scope->scope_type, 'scopeValue' => $scope->scope_value], $actor);
        $this->emit(RbacEvents::USER_SCOPE_ASSIGNED, $authUserId, ['scopeType' => $scope->scope_type, 'scopeValue' => $scope->scope_value]);

        return $scope;
    }

    public function revoke(RbacUserScope $scope, ?string $actor = null): RbacUserScope
    {
        $scope->update(['active' => false, 'effective_to' => now()]);
        RbacChangeAudit::record('USER_SCOPE_REVOKED', 'USER_SCOPE', $scope->auth_user_id,
            ['scopeType' => $scope->scope_type, 'scopeValue' => $scope->scope_value], ['active' => false], $actor);
        $this->emit(RbacEvents::USER_SCOPE_REVOKED, $scope->auth_user_id, ['scopeType' => $scope->scope_type, 'scopeValue' => $scope->scope_value]);

        return $scope;
    }

    /** @return \Illuminate\Support\Collection<int,RbacUserScope> active scopes for a user */
    public function scopesFor(string $authUserId): \Illuminate\Support\Collection
    {
        return RbacUserScope::query()->where('auth_user_id', $authUserId)->where('active', true)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', now()))
            ->get();
    }

    /**
     * Scope check (DD §8.5 rule): a user is within scope if they hold GLOBAL, an OPERATOR scope
     * matching the operator, or an exact scope_type+value grant. A platform SUPER_ADMIN bypasses.
     */
    public function withinScope(string $authUserId, string $scopeType, string $scopeValue, ?string $operator = null): bool
    {
        $operator ??= Context::operatorCode();
        foreach ($this->scopesFor($authUserId) as $s) {
            if ($s->scope_type === RbacUserScope::GLOBAL) {
                return true;
            }
            if ($s->scope_type === RbacUserScope::OPERATOR && $s->scope_value === $operator) {
                return true;
            }
            if ($s->scope_type === $scopeType && $s->scope_value === $scopeValue) {
                return true;
            }
        }

        return false;
    }

    /** Whether a permission requires a scope check (DD §8.2 scope_required). */
    public function permissionRequiresScope(string $permissionCode): bool
    {
        return (bool) RbacPermissionMeta::query()->whereKey($permissionCode)->value('scope_required');
    }

    /** @param array<string,mixed> $extra */
    private function emit(string $type, string $authUserId, array $extra): void
    {
        $this->events->publish(new DomainEvent(
            type: $type, topic: RbacEvents::TOPIC,
            payload: ['authUserId' => $authUserId] + $extra,
            aggregateType: 'RbacUserScope', aggregateId: $authUserId,
        ));
    }
}
