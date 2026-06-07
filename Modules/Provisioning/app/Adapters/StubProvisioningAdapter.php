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

        return ProvisioningResult::confirmed(
            externalRef: 'NMS-'.strtoupper(substr(Id::make('x'), 2, 12)),
            response: ['accepted' => true, 'observedStatus' => $command->desired_state['desiredStatus'] ?? 'ACTIVE'],
        );
    }
}
