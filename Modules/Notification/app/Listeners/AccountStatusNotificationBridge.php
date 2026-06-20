<?php

namespace Modules\Notification\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;
use Modules\Notification\Services\NotificationOrchestrator;

/**
 * NOT-01 consumes ILM-CFG-01's CustomerAccountStatusChanged and, when the catalog marked the
 * transition customer-visible, routes a customer notice through the orchestrator — channels
 * come from the operator's routing rules + the customer's preferences, not hardcoded here.
 * Without this a customer-visible account suspension/restoration told the customer nothing.
 */
class AccountStatusNotificationBridge
{
    public function __construct(private readonly NotificationOrchestrator $orchestrator) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'CustomerAccountStatusChanged') {
            return;
        }
        $payload = $event->payload ?? [];
        if (! ($payload['customerVisible'] ?? false) || empty($payload['accountId'])) {
            return; // internal-only transition: no customer notice
        }
        $operator = $event->operator_code ?? ($payload['operatorCode'] ?? null);
        if (! $operator) {
            return;
        }

        $account = CustomerAccount::query()->where('account_id', $payload['accountId'])->first();
        $customer = $account?->customer_id ? Customer::query()->find($account->customer_id) : null;
        if (! $customer) {
            return; // no resolvable recipient
        }

        $contacts = array_filter([
            'EMAIL' => $customer->email,
            'SMS' => $customer->primary_msisdn,
        ]);

        $this->orchestrator->ingest('CustomerAccountStatusChanged', $operator, [
            'status' => $payload['status'] ?? null,
            'subStatus' => $payload['subStatus'] ?? null,
        ], [
            'customerId' => $customer->customer_id,
            'sourceEntityId' => $payload['accountId'],
            'sourceEventId' => 'acct-status-'.$payload['accountId'].'-'.($payload['status'] ?? '').'-'.($payload['subStatus'] ?? ''),
            'contacts' => $contacts,
        ]);
    }
}
