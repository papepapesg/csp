<?php

namespace Modules\Notification\Routing;

use Modules\Notification\Models\NotificationRoutingRule;

/**
 * One resolved channel in the routing decision: which channel, which template purpose, in
 * what priority, with the transactional/marketing category and urgency that the preference
 * and regulatory filters read. Carries a deferUntil once the regulatory filter queues it.
 */
final class ChannelDecision
{
    public function __construct(
        public readonly string $channel,
        public readonly string $purposeCode,
        public readonly int $priority,
        public readonly string $urgency,
        public readonly string $category,
        public readonly bool $needsPdf = false,
        public ?\DateTimeInterface $deferUntil = null,
    ) {}

    public static function fromRule(NotificationRoutingRule $rule): self
    {
        return new self(
            $rule->channel,
            $rule->template_purpose_code,
            $rule->priority,
            $rule->urgency,
            $rule->category,
            $rule->needs_pdf,
        );
    }

    public function isUrgent(): bool
    {
        return $this->urgency === NotificationRoutingRule::URGENCY_URGENT;
    }

    public function isMarketing(): bool
    {
        return $this->category === NotificationRoutingRule::CATEGORY_MARKETING;
    }
}
