<?php

namespace Modules\Ilm\Services;

use App\Foundation\Events\DomainEvent;
use App\Foundation\Events\EventBus;
use Illuminate\Support\Facades\DB;
use Modules\Ilm\Events\IlmEvents;
use Modules\Ilm\Models\CustomerAccount;

/**
 * Authoritative writes for Customer Accounts (ILM-CFG-01 §2.2).
 */
class AccountService
{
    public function __construct(private readonly EventBus $events) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CustomerAccount
    {
        return DB::transaction(function () use ($data) {
            $account = CustomerAccount::query()->create($data);

            $this->events->publish(new DomainEvent(
                type: IlmEvents::CUSTOMER_ACCOUNT_CREATED,
                topic: IlmEvents::TOPIC,
                payload: [
                    'accountId' => $account->account_id,
                    'accountNumber' => $account->account_number,
                    'customerId' => $account->customer_id,
                ],
                aggregateType: 'CustomerAccount',
                aggregateId: $account->account_id,
            ));

            return $account;
        });
    }

    /**
     * Update account fields. A status/sub-status change records the timestamp
     * and emits a status-changed event (history table is a later hardening item).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(CustomerAccount $account, array $data): CustomerAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $statusChanged = (isset($data['status']) && $data['status'] !== $account->status)
                || (isset($data['sub_status']) && $data['sub_status'] !== $account->sub_status);

            if ($statusChanged) {
                $data['sub_status_changed_at'] = now();
            }

            $account->update($data);

            if ($statusChanged) {
                $this->events->publish(new DomainEvent(
                    type: IlmEvents::CUSTOMER_ACCOUNT_STATUS_CHANGED,
                    topic: IlmEvents::TOPIC,
                    payload: [
                        'accountId' => $account->account_id,
                        'status' => $account->status,
                        'subStatus' => $account->sub_status,
                    ],
                    aggregateType: 'CustomerAccount',
                    aggregateId: $account->account_id,
                ));
            }

            return $account;
        });
    }
}
