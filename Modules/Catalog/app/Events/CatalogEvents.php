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

    // SIP-04 bundle launch lifecycle.
    public const BUNDLE_CREATED = 'CommercialBundleCreated';

    public const BUNDLE_VALIDATED = 'CommercialBundleValidated';

    public const BUNDLE_APPROVED = 'CommercialBundleApproved';

    public const BUNDLE_ACTIVATED = 'CommercialBundleActivated';

    public const BUNDLE_RETIRED = 'CommercialBundleRetired';

    // SIP-05 campaigns.
    public const CAMPAIGN_CREATED = 'PromotionCampaignCreated';

    public const CAMPAIGN_ACTIVATED = 'PromotionCampaignActivated';

    public const CAMPAIGN_REDEEMED = 'PromotionCampaignRedeemed';
}
