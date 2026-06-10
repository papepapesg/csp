<?php

namespace Modules\Billing\Services;

use App\Foundation\Errors\DomainException;
use Modules\Ilm\Models\Customer;
use Modules\Ilm\Models\CustomerAccount;

/**
 * BIL-02 Core CustomerSnapshotService (R-GEN-01-F-6). Captures an immutable
 * point-in-time copy of the customer's invoice-relevant facts — identity,
 * language preference (drives line localization, R-GEN-01-L-5), tax category
 * and billing location (drives PLM-CFG-02 tax) — at generation time.
 *
 * If the customer cannot be resolved the snapshot fails LOUDLY: the generator
 * must NOT write an invoice with a partial snapshot (the caller treats this as
 * CUSTOMER_SNAPSHOT_FETCH_FAILED and retries, rather than persisting a bad doc).
 */
class CustomerSnapshotService
{
    /**
     * @return array<string,mixed>
     */
    public function captureSnapshot(string $customerId, ?string $accountId = null): array
    {
        $customer = Customer::query()->find($customerId);
        if (! $customer) {
            // Recoverable (R-GEN-01-F-6): the generator queues + retries; it must
            // NOT write an invoice with a partial snapshot.
            throw new DomainException('CUSTOMER_SNAPSHOT_FETCH_FAILED', "Customer [{$customerId}] could not be resolved for snapshot.", status: 503, retryable: true);
        }

        $account = $accountId ? CustomerAccount::query()->find($accountId) : null;

        return [
            'customerId' => $customer->customer_id,
            'name' => $customer->name,
            'type' => $customer->type,                                   // RES | COM
            'customerCategory' => $customer->type === 'COM' ? 'BUSINESS' : 'RESIDENTIAL',
            'preferredLanguage' => $customer->preferred_language ?? 'en',
            'email' => $customer->email,
            'msisdn' => $customer->primary_msisdn,
            'billingAddress' => $account?->service_address,
            'accountId' => $account?->account_id,
            'capturedAt' => now()->toIso8601String(),
        ];
    }
}
