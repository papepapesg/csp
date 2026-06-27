<?php

namespace Modules\Notification\Icn\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Notification\Icn\ChannelDispatchResult;
use Modules\Notification\Icn\RecipientInfo;
use Modules\Notification\Icn\RenderedMessage;
use Modules\Notification\Icn\StaffChannelAdapter;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * Shared EMAIL adapter behaviour for the stub email providers (smtp-classic,
 * microsoft-graph-mail). Resolves the address from identity_jsonb.address, else the lazy
 * FOUNDATION_AUTH profile email (§7.1); no address -> IDENTITY_UNRESOLVABLE. "bounce" in the
 * address -> BOUNCE (terminal); "@transient" -> a transient failure (retried). The two
 * concrete subclasses differ only by adapterImplCode() — a real deployment swaps SMTP for
 * Graph by changing the binding, not the dispatcher.
 */
abstract class EmailAdapterBase implements StaffChannelAdapter
{
    protected string $from = '';

    public function init(array $config): void
    {
        $this->from = (string) ($config['from'] ?? 'sophix-noreply@sophix.local');
    }

    public function dispatch(StaffNotificationDelivery $delivery, RenderedMessage $message, RecipientInfo $recipient): ChannelDispatchResult
    {
        $address = $recipient->identityJsonb['address']
            ?? ($recipient->fallbackProfile()['email'] ?? null);

        if (! $address) {
            return ChannelDispatchResult::fail('IDENTITY_UNRESOLVABLE');
        }
        if (str_contains($address, 'bounce')) {
            return ChannelDispatchResult::fail('BOUNCE', ['smtp_code' => 550]);
        }
        if (str_contains($address, '@transient')) {
            return ChannelDispatchResult::fail('CHANNEL_DOWN', ['smtp_code' => 421]);
        }

        Log::info('icn.email.dispatch', ['delivery_id' => $delivery->delivery_id, 'impl' => $this->adapterImplCode(), 'status' => 'DISPATCHED']);
        Log::debug('icn.email.dispatch.detail', ['to' => $address, 'from' => $this->from, 'subject' => $message->subject]);

        $messageId = '<'.bin2hex(random_bytes(8)).'@'.($this->from ?: 'sophix.local').'>';

        return ChannelDispatchResult::ok($messageId, ['message_id' => $messageId, 'impl' => $this->adapterImplCode()]);
    }

    public function resolveIdentity(array $userProfile): ?array
    {
        return isset($userProfile['email']) ? ['address' => $userProfile['email']] : null;
    }
}
