<?php

namespace Modules\Provisioning\Listeners;

use App\Foundation\Events\OutboxEventPublished;
use App\Foundation\Support\Context;
use Modules\Provisioning\Models\ProvisioningDesiredState;
use Modules\Provisioning\Services\ProvisioningService;
use Modules\Subscription\Models\Subscription;

/**
 * ILM-CFG-01 R-ILM-S-3: when a customer-account status change is flagged
 * affects_provisioning, ILM emits CustomerAccountStatusChanged and provisioning applies the
 * network-level change. This consumes that event and re-broadcasts each of the account's
 * subscriptions' provisioned targets at the mapped status (ACTIVE -> restore, otherwise
 * SUSPEND). Without it an account suspension/restore never reached the network.
 */
class SyncProvisioningOnAccountStatusChanged
{
    public function __construct(private readonly ProvisioningService $provisioning) {}

    public function handle(OutboxEventPublished $published): void
    {
        $event = $published->event;
        if ($event->event_type !== 'CustomerAccountStatusChanged') {
            return;
        }
        $payload = $event->payload ?? [];
        // R-ILM-S-3: only act when the operator's catalog marked the transition provisioning-affecting.
        if (! ($payload['affectsProvisioning'] ?? false) || empty($payload['accountId'])) {
            return;
        }
        if ($event->operator_code) {
            Context::setOperatorCode($event->operator_code);
        }

        $desiredStatus = ($payload['status'] ?? null) === 'ACTIVE' ? 'ACTIVE' : 'SUSPENDED';

        Subscription::query()->where('account_id', $payload['accountId'])->pluck('subscription_id')
            ->each(function (string $subscriptionId) use ($desiredStatus) {
                $targets = ProvisioningDesiredState::query()->where('subscription_id', $subscriptionId)->get();
                if ($targets->isEmpty()) {
                    return; // nothing provisioned for this subscription
                }
                $commands = $targets->map(fn (ProvisioningDesiredState $d) => [
                    'target_code' => $d->target_code,
                    'service_ref' => $d->service_ref,
                    'desired_state' => ['desiredStatus' => $desiredStatus],
                ])->all();
                $this->provisioning->broadcast($subscriptionId, 'ACCOUNT_STATUS_SYNC', $commands);
            });
    }
}
