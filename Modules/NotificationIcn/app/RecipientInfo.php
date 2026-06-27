<?php

namespace Modules\Notification\Icn;

/**
 * The recipient context an adapter receives: the staff user id, the per-channel identity
 * (from staff_notification_user_channel_identity, or null), and a lazy fallback that calls
 * the FOUNDATION_AUTH user-profile lookup only if the adapter needs it (§7.0).
 */
final class RecipientInfo
{
    /** @var (callable():?array)|null */
    private $fallbackProfileLookup;

    /** @param array<string,mixed>|null $identityJsonb */
    public function __construct(
        public readonly string $userId,
        public readonly ?array $identityJsonb,
        ?callable $fallbackProfileLookup = null,
    ) {
        $this->fallbackProfileLookup = $fallbackProfileLookup;
    }

    /** @return array<string,mixed>|null the user's directory profile (email, name), lazily fetched */
    public function fallbackProfile(): ?array
    {
        return $this->fallbackProfileLookup ? ($this->fallbackProfileLookup)() : null;
    }
}
