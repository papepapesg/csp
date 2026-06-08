<?php

namespace Modules\Catalog\Events;

/**
 * Catalog domain-event types + topic (PLM/SIP/RLM/ILM-CFG-02).
 */
final class CatalogEvents
{
    public const TOPIC = 'catalog.reference';

    public const SERVICE_CREATED = 'ServiceCreated';

    public const PACKAGE_CREATED = 'PackageCreated';

    public const PACKAGE_VERSION_ADDED = 'PackageVersionAdded';

    public const PACKAGE_ACTIVATED = 'PackageActivated';

    public const TECH_REGION_CREATED = 'TechRegionCreated';

    public const HOMEPASS_CREATED = 'HomePassCreated';

    public const HOMEPASS_STATUS_CHANGED = 'HomePassStatusChanged';

    // PLM-CFG-03 wallet catalog lifecycle.
    public const WALLET_CREATED = 'WalletCreated';

    public const WALLET_UPDATED = 'WalletUpdated';

    public const WALLET_ACTIVATED = 'WalletActivated';

    public const WALLET_RETIRED = 'WalletRetired';
}
