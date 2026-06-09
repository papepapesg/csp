<?php

namespace Modules\Provisioning\Tests\Feature;

use App\Foundation\Support\Context;
use App\Foundation\Support\Id;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Provisioning\Contracts\ProvisioningAdapter;
use Modules\Provisioning\Contracts\ProvisioningResult;
use Modules\Provisioning\Models\ProvisioningAdapterConfig;
use Modules\Provisioning\Models\ProvisioningCommand;
use Modules\Provisioning\Services\ProvisioningService;
use Tests\TestCase;

/**
 * PROV-INT-01 §10.2: each command is dispatched through the adapter bound to ITS
 * target (provisioning_adapter_config.adapter_class). Targets with no binding fall
 * back to the deployment's default driver. Proves the protocol-per-backend routing.
 */
class AdapterRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Context::setOperatorCode('WIK');
    }

    public function test_command_routes_to_the_adapter_bound_to_its_target(): void
    {
        // Bind one target to a custom vendor adapter; leave another unbound.
        ProvisioningAdapterConfig::query()->create([
            'adapter_config_id' => Id::make('pac'), 'operator_code' => 'WIK',
            'target_code' => 'SIP_VOICE_KE', 'adapter_class' => RoutingProbeAdapter::class, 'status' => 'ACTIVE',
        ]);

        $svc = app(ProvisioningService::class);

        // Voice command -> the bound SIP adapter (distinctive external_ref).
        $voice = $svc->broadcast('sub_1', 'ACTIVATE', [[
            'target_code' => 'SIP_VOICE_KE', 'desired_state' => ['desiredStatus' => 'ACTIVE'],
        ]])[0];
        $this->assertSame(ProvisioningCommand::CONFIRMED, $voice->status);
        $this->assertSame('PROBE-SIP-ADAPTER', $voice->external_ref);

        // Unbound target -> the default driver (stub), a different adapter.
        $data = $svc->broadcast('sub_1', 'ACTIVATE', [[
            'target_code' => 'DEFAULT_NMS', 'desired_state' => ['desiredStatus' => 'ACTIVE'],
        ]])[0];
        $this->assertSame(ProvisioningCommand::CONFIRMED, $data->status);
        $this->assertStringStartsWith('NMS-', (string) $data->external_ref);
    }

    public function test_a_suspended_binding_falls_back_to_the_default_driver(): void
    {
        ProvisioningAdapterConfig::query()->create([
            'adapter_config_id' => Id::make('pac'), 'operator_code' => 'WIK',
            'target_code' => 'SIP_VOICE_KE', 'adapter_class' => RoutingProbeAdapter::class, 'status' => 'SUSPENDED',
        ]);

        $cmd = app(ProvisioningService::class)->broadcast('sub_2', 'ACTIVATE', [[
            'target_code' => 'SIP_VOICE_KE', 'desired_state' => ['desiredStatus' => 'ACTIVE'],
        ]])[0];

        // SUSPENDED binding is ignored -> default stub, not the probe adapter.
        $this->assertStringStartsWith('NMS-', (string) $cmd->external_ref);
    }
}

/** A stand-in vendor adapter that tags its confirmations so routing is observable. */
class RoutingProbeAdapter implements ProvisioningAdapter
{
    public function dispatch(ProvisioningCommand $command): ProvisioningResult
    {
        return ProvisioningResult::confirmed(
            externalRef: 'PROBE-SIP-ADAPTER',
            response: ['accepted' => true, 'observedStatus' => $command->desired_state['desiredStatus'] ?? 'ACTIVE', 'adapter' => 'sip-probe'],
        );
    }

    public function fetchObserved(string $targetCode, string $subscriberKey, string $desiredStatus, array $desiredProfile = []): ?array
    {
        return ['observedStatus' => $desiredStatus, 'observedProfile' => $desiredProfile];
    }
}
