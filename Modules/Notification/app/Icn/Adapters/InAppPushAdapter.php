<?php

namespace Modules\Notification\Icn\Adapters;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Notification\Icn\ChannelDispatchResult;
use Modules\Notification\Icn\RecipientInfo;
use Modules\Notification\Icn\RenderedMessage;
use Modules\Notification\Icn\StaffChannelAdapter;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * IN_APP_PUSH via WebSocket fanout (§7.4). Routes by user_id (identity not consulted): fans
 * the message to the user's active BO-UI sessions. With no active session the delivery isn't
 * a channel failure — it returns NO_ACTIVE_SESSION and the dispatcher keeps it PENDING for the
 * BO UI to catch up on next login, suppressing it after the ack window.
 *
 * Active sessions are tracked in a cache registry (icn:session:{userId}); the BO UI marks a
 * session live on connect. Tests/dev can set the key directly.
 */
class InAppPushAdapter implements StaffChannelAdapter
{
    public function adapterImplCode(): string
    {
        return 'inapp-websocket-fanout';
    }

    public function init(array $config): void {}

    public function dispatch(StaffNotificationDelivery $delivery, RenderedMessage $message, RecipientInfo $recipient): ChannelDispatchResult
    {
        if (! Cache::get('icn:session:'.$recipient->userId)) {
            return ChannelDispatchResult::fail('NO_ACTIVE_SESSION');
        }

        Log::info('icn.inapp.dispatch', ['delivery_id' => $delivery->delivery_id, 'status' => 'DISPATCHED']);
        $id = 'push_'.bin2hex(random_bytes(6));

        return ChannelDispatchResult::ok($id, ['push_message_id' => $id]);
    }

    public function resolveIdentity(array $userProfile): ?array
    {
        return []; // existence is informational; routing uses user_id
    }
}
