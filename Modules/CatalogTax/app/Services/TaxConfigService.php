<?php

namespace Modules\Catalog\Tax\Services;

use App\Foundation\Errors\DomainException;
use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Events\CatalogEvents;
use Modules\Catalog\Tax\Models\TaxGroup;
use Modules\Catalog\Tax\Models\TaxRule;

/**
 * PLM-CFG-02 tax configuration admin. Tax rules are versioned by effective window
 * and never deleted (R-PLM-02-CT-1): creating a new version of a code closes the
 * prior version's effective_until so windows never overlap (the compute path reads
 * the one active at taxable_at). Every write emits TaxConfigChanged for downstream
 * cache refresh (R-PLM-02 §events).
 */
class TaxConfigService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * Create a tax rule (a first definition or a new effective-dated version of an
     * existing code). A new version auto-closes the currently-open version of the
     * same code at this version's effective_from.
     *
     * @param  array<string,mixed>  $data
     */
    public function createRule(array $data): TaxRule
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $code = $data['code'];
        $from = $data['effective_from'] ?? now();
        $this->validateRule($data);

        return DB::transaction(function () use ($operator, $code, $from, $data) {
            // Close any open prior version so the new window does not overlap (R-PLM-02-CT-1).
            TaxRule::query()->where('operator_code', $operator)->where('code', $code)
                ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $from))
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $from))
                ->update(['effective_until' => $from]);

            $rule = TaxRule::query()->create($data + [
                'tax_rule_id' => Id::make('txr'),
                'operator_code' => $operator,
                'effective_from' => $from,
            ]);
            $this->emit($operator, 'RULE_ADDED', ['code' => $code, 'taxRuleId' => $rule->tax_rule_id]);

            return $rule;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateRule(TaxRule $rule, array $data): TaxRule
    {
        $this->validateRule(array_merge($rule->getAttributes(), $data));

        return DB::transaction(function () use ($rule, $data) {
            $rule->update($data);
            $this->emit($rule->operator_code, 'RULE_UPDATED', ['code' => $rule->code, 'taxRuleId' => $rule->tax_rule_id]);

            return $rule->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function createGroup(array $data): TaxGroup
    {
        $operator = $data['operator_code'] ?? Context::operatorCode();
        $code = $data['code'];
        if (TaxGroup::query()->where('operator_code', $operator)->where('code', $code)->exists()) {
            throw new DomainException('R-PLM-CFG-02-G-1', "Tax group {$code} already exists.", 422);
        }
        $this->validateGroupMembers($operator, $data['order_within_group'] ?? []);

        return DB::transaction(function () use ($operator, $code, $data) {
            $group = TaxGroup::query()->create($data + [
                'tax_group_id' => Id::make('txg'),
                'operator_code' => $operator,
            ]);
            $this->emit($operator, 'GROUP_ADDED', ['code' => $code, 'taxGroupId' => $group->tax_group_id]);

            return $group;
        });
    }

    /** @param array<string,mixed> $data */
    public function updateGroup(TaxGroup $group, array $data): TaxGroup
    {
        if (array_key_exists('order_within_group', $data)) {
            $this->validateGroupMembers($group->operator_code, $data['order_within_group'] ?? []);
        }

        return DB::transaction(function () use ($group, $data) {
            $group->update($data);
            $this->emit($group->operator_code, 'GROUP_CHANGED', ['code' => $group->code, 'taxGroupId' => $group->tax_group_id]);

            return $group->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    private function validateRule(array $data): void
    {
        $rate = (float) ($data['rate'] ?? 0);
        if ($rate < 0 || $rate > 1) {
            throw new DomainException('R-PLM-CFG-02-CT-2', 'Tax rate must be a fraction between 0 and 1 (e.g. 0.16).', 422);
        }
        $base = $data['base_method'] ?? 'BASE';
        if (! in_array($base, ['BASE', 'BASE_PLUS_PRIOR'], true)) {
            throw new DomainException('R-PLM-CFG-02-CT-5', 'base_method must be BASE or BASE_PLUS_PRIOR.', 422);
        }
    }

    /** Each member code must be a real rule code in this operator's catalog. */
    private function validateGroupMembers(string $operator, array $codes): void
    {
        foreach ($codes as $code) {
            if (! TaxRule::query()->where('operator_code', $operator)->where('code', $code)->exists()) {
                throw new DomainException('R-PLM-CFG-02-G-3', "Tax group references unknown rule code {$code}.", 422);
            }
        }
    }

    /** @param array<string,mixed> $context */
    private function emit(string $operator, string $changeType, array $context): void
    {
        $this->events->publish(new DomainEvent(
            type: CatalogEvents::TAX_CONFIG_CHANGED,
            topic: CatalogEvents::TOPIC,
            payload: ['operatorCode' => $operator, 'changeType' => $changeType] + $context,
            aggregateType: 'TaxConfig',
            aggregateId: $context['taxRuleId'] ?? $context['taxGroupId'] ?? $operator,
        ));
    }
}
