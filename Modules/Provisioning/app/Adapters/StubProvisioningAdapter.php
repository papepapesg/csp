<?php

namespace Modules\Provisioning\Adapters;

use App\Foundation\Support\Id;
use Illuminate\Support\Facades\Log;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Contracts\ProvisioningResult;
use Modules\Provisioning\Models\ProvisioningCommand;

/**
 * Stub NMS adapter: "sends" the command (logs a vendor-shaped instruction) and
 * returns a confirmation, so activation/restriction/termination flows can run
 * end-to-end without a real network. Honours desired_state.forceFail for tests.
 * Swap to a real adapter by binding ProvisioningAdapter to a vendor class.
 */
class StubProvisioningAdapter implements ProvisioningAdapter
{
    public function dispatch(ProvisioningCommand $command): ProvisioningResult
    {
        $instruction = [
            'target' => $command->target_code,
            'action' => $command->action,
            'subscriptionId' => $command->subscription_id,
            'serviceRef' => $command->service_ref,
            'desiredState' => $command->desired_state,
        ];

        // This is where a real driver would open a session and send vendor CLI/NETCONF.
        Log::info('[provisioning:stub] dispatch', $instruction);

        if (($command->desired_state['forceFail'] ?? false) === true) {
            return ProvisioningResult::failed('Simulated NMS rejection');
        }

        $ref = 'NMS-'.strtoupper(substr(Id::make('x'), 2, 12));
        $observed = ['accepted' => true, 'observedStatus' => $command->desired_state['desiredStatus'] ?? 'ACTIVE'];

        // Async vendors return only "accepted"; the status worker resolves later.
        if (($command->desired_state['simulateAsync'] ?? false) === true) {
            return ProvisioningResult::accepted($ref, $observed);
        }

        return ProvisioningResult::confirmed($ref, $observed);
    }

    public function pollStatus(ProvisioningCommand $command): ?ProvisioningResult
    {
        // The stub completes async commands on the first poll (a real driver would
        // query the vendor and return null while still in progress).
        return ProvisioningResult::confirmed(
            externalRef: (string) $command->external_ref,
            response: ['observedStatus' => $command->desired_state['desiredStatus'] ?? 'ACTIVE'],
        );
    }

    /**
     * Simulated poll: the stub network mirrors the desired state (so a healthy
     * network reconciles clean), unless the desired profile carries a test hint:
     *   simulateObservedStatus -> return that status (drift)
     *   simulateNotPresent     -> return null (subscriber missing on target)
     */
    public function fetchObserved(string $targetCode, string $subscriberKey, string $desiredStatus, array $desiredProfile = []): ?array
    {
        if (($desiredProfile['simulateNotPresent'] ?? false) === true) {
            return null;
        }

        return [
            'observedStatus' => $desiredProfile['simulateObservedStatus'] ?? $desiredStatus,
            'observedProfile' => $desiredProfile,
        ];
    }
}
