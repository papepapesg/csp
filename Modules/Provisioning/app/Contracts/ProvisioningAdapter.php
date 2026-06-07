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
}
