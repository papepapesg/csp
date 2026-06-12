<?php

namespace Modules\Notification\Routing;

use Modules\Notification\Models\NotificationRoutingRule;

/**
 * NOT-01 routing (R-NOT-01-R-1/R-2/R-6). (operator, event_type) -> ordered ChannelDecision
 * list from notification_routing_rule, lowest priority first, only enabled rules, with the
 * optional payload-based conditions applied. An empty result means the event is internal-only
 * (no customer-facing notification).
 */
class RoutingResolverService
{
    /**
     * @param array<string,mixed> $payload
     * @return list<ChannelDecision>
     */
    public function resolve(string $eventType, string $operator, array $payload = []): array
    {
        $rules = NotificationRoutingRule::query()
            ->where('operator_code', $operator)
            ->where('event_type', $eventType)
            ->where('enabled', true)
            ->orderBy('priority')
            ->get();

        $decisions = [];
        foreach ($rules as $rule) {
            if (! $this->conditionsMatch($rule->conditions, $payload)) {
                continue;
            }
            $decisions[] = ChannelDecision::fromRule($rule);
        }

        return $decisions;
    }

    /**
     * Optional payload-based conditions: a flat map of dot-path => expected value, all of
     * which must match. Null/empty conditions always match.
     *
     * @param array<string,mixed>|null $conditions
     * @param array<string,mixed> $payload
     */
    private function conditionsMatch(?array $conditions, array $payload): bool
    {
        if (empty($conditions)) {
            return true;
        }
        foreach ($conditions as $path => $expected) {
            if (data_get($payload, $path) != $expected) {
                return false;
            }
        }

        return true;
    }
}
