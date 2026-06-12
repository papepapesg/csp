<?php

namespace Modules\Notification\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Ilm\Models\Customer;
use Modules\Notification\Services\NotificationOrchestrator;
use Modules\Subscription\Models\Subscription;

/**
 * NOT-01 consumes BIL-04's dunning level-transition events and routes a customer-facing notice
 * through the orchestrator — so the channel(s) come from the operator's notification_routing_rule
 * and the customer's channel preferences, never from hardcoded logic in the billing module.
 * Adding WhatsApp/Telegram for dunning notices is a routing-rule row + a channel adapter; no
 * code change here.
 */
class DunningNotificationBridge
{
    public function __construct(private readonly NotificationOrchestrator $orchestrator) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'DunningStageAdvanced') {
            return; // one notice per level transition; entry also emits this
        }
        $payload = $event->payload ?? [];
        $operator = $event->operator_code ?? ($payload['operatorCode'] ?? null);
        if (! $operator) {
            return;
        }

        $subscription = isset($payload['subscriptionId']) ? Subscription::query()->find($payload['subscriptionId']) : null;
        $customer = $subscription?->customer_id ? Customer::query()->find($subscription->customer_id) : null;
        if (! $customer) {
            return; // no resolvable recipient
        }

        // NOT-01 reads contact details from CRM; the operator's routing rules pick which of
        // these channels actually fire (and the customer's preferences/locale/window apply).
        $contacts = array_filter([
            'EMAIL' => $customer->email,
            'SMS' => $customer->primary_msisdn,
            'WHATSAPP' => $customer->primary_msisdn,
        ]);

        $this->orchestrator->ingest('DunningStageAdvanced', $operator, [
            'level' => $payload['level'] ?? null,
            'levelName' => $payload['levelName'] ?? null,
            'amount' => $payload['debt'] ?? null,
            'accountId' => $payload['accountId'] ?? null,
        ], [
            'customerId' => $customer->customer_id,
            'sourceEntityId' => $payload['accountId'] ?? $subscription->subscription_id,
            'sourceEventId' => 'dun-notice-'.($payload['accountId'] ?? '').'-L'.($payload['level'] ?? ''),
            'contacts' => $contacts,
        ]);
    }
}
