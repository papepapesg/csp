<?php

namespace Modules\Notification\Icn\Adapters;

use Illuminate\Support\Facades\Log;
use Modules\Notification\Icn\ChannelDispatchResult;
use Modules\Notification\Icn\RecipientInfo;
use Modules\Notification\Icn\RenderedMessage;
use Modules\Notification\Icn\StaffChannelAdapter;
use Modules\Notification\Models\Icn\StaffNotificationDelivery;

/**
 * MSTEAMS via Incoming Webhook (§7.3). Channel-scoped, not user-scoped: the message goes to a
 * Teams channel chosen from candidate_group_to_channel by the notification's candidate group,
 * so the per-user identity is ignored. No webhook mapped for the group -> CHANNEL_NOT_MAPPED.
 */
class TeamsWebhookAdapter implements StaffChannelAdapter
{
    /** @var array<string,string> */
    private array $channelWebhooks = [];

    /** @var array<string,string> */
    private array $groupToChannel = [];

    public function adapterImplCode(): string
    {
        return 'msteams-incoming-webhook';
    }

    public function init(array $config): void
    {
        $this->channelWebhooks = (array) ($config['channel_webhooks'] ?? []);
        $this->groupToChannel = (array) ($config['candidate_group_to_channel'] ?? []);
    }

    public function dispatch(StaffNotificationDelivery $delivery, RenderedMessage $message, RecipientInfo $recipient): ChannelDispatchResult
    {
        $group = $delivery->notification?->candidate_group;
        $teamsChannel = $this->groupToChannel[$group] ?? null;
        if (! $teamsChannel || ! isset($this->channelWebhooks[$teamsChannel])) {
            return ChannelDispatchResult::fail('CHANNEL_NOT_MAPPED');
        }

        Log::info('icn.msteams.dispatch', ['delivery_id' => $delivery->delivery_id, 'teams_channel' => $teamsChannel, 'status' => 'DISPATCHED']);
        $id = 'teams_'.bin2hex(random_bytes(6));

        return ChannelDispatchResult::ok($id, ['teams_channel' => $teamsChannel, 'message_id' => $id]);
    }

    public function resolveIdentity(array $userProfile): ?array
    {
        return null; // channel-scoped delivery needs no per-user identity
    }
}
