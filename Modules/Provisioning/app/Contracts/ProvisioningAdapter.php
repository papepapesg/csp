<?php

namespace Modules\Provisioning\Contracts;

use Modules\Provisioning\Models\ProvisioningCommand;

/**
 * Vendor-agnostic provisioning adapter (PROV-INT-01). The default driver is a
 * stub that simulates an NMS so flows run end-to-end; a real driver (Huawei NCE,
 * Nokia, CMTS, SIP platform) implements the same contract and writes through the
 * same command ledger. Selected via SOPHIX_PROVISIONING_DRIVER.
 */
interface ProvisioningAdapter
{
    public function dispatch(ProvisioningCommand $command): ProvisioningResult;

    /**
     * Poll a target for the observed state of one subscriber key (PROV-INT-01
     * §7.3 reconciliation). Returns the observed status + profile, or null when
     * the subscriber is not present on the target.
     *
     * @param  array<string,mixed>  $desiredProfile  hint for the lookup/simulation
     * @return array{observedStatus:?string,observedProfile:array<string,mixed>}|null
     */
    public function fetchObserved(string $targetCode, string $subscriberKey, string $desiredStatus, array $desiredProfile = []): ?array;

    /**
     * PROV §7.2 async: poll the final outcome of a previously-ACCEPTED command.
     * Returns a resolved result (confirmed/failed) or null while still pending.
     */
    public function pollStatus(ProvisioningCommand $command): ?ProvisioningResult;
}
