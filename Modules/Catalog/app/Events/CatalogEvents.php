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

    public const HOMEPASS_REACHED_SELLABLE = 'HomePassReachedSellable'; // R-RLM-CFG-01-H-6 lead-notify hook

    public const HOMEPASS_ADDRESS_CORRECTED = 'HomePassAddressCorrected'; // R-RLM-CFG-01-H-14

    // SIP-03 discount assignment lifecycle.
    public const DISCOUNT_ASSIGNMENT_CREATED = 'DiscountAssignmentCreated';

    public const DISCOUNT_ASSIGNMENT_APPROVAL_REQUIRED = 'DiscountAssignmentApprovalRequired';

    public const DISCOUNT_ASSIGNMENT_ACTIVATED = 'DiscountAssignmentActivated';

    public const DISCOUNT_ASSIGNMENT_CANCELLED = 'DiscountAssignmentCancelled';

    public const DISCOUNT_ASSIGNMENT_EXPIRED = 'DiscountAssignmentExpired';

    public const DISCOUNT_ASSIGNMENT_REJECTED = 'DiscountAssignmentRejected';

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

    // SIP-02 package launch lifecycle.
    public const PACKAGE_LAUNCH_PLAN_CREATED = 'PackageLaunchPlanCreated';

    public const PACKAGE_LAUNCH_VALIDATED = 'PackageLaunchValidated';

    public const PACKAGE_LAUNCH_APPROVAL_REQUIRED = 'PackageLaunchApprovalRequired';

    public const PACKAGE_LAUNCH_APPROVED = 'PackageLaunchApproved';

    public const PACKAGE_LAUNCH_REJECTED = 'PackageLaunchRejected';

    public const PACKAGE_AVAILABILITY_CHANGED = 'PackageAvailabilityChanged';

    public const PACKAGE_END_OF_SALE = 'PackageEndOfSale';

    public const PACKAGE_RETIRED = 'PackageRetired';

    // PLM-CFG-07 voice tariff catalog.
    public const VOICE_TARIFF_PLAN_CREATED = 'VoiceTariffPlanCreated';

    public const VOICE_TARIFF_PLAN_ACTIVATED = 'VoiceTariffPlanActivated';

    public const VOICE_TARIFF_PLAN_RETIRED = 'VoiceTariffPlanRetired';

    public const VOICE_TARIFF_RATE_CHANGED = 'VoiceTariffRateChanged';

    public const VOICE_DESTINATION_PREFIX_CHANGED = 'VoiceDestinationPrefixChanged';

    public const VOICE_TARIFF_BINDING_CHANGED = 'VoiceTariffBindingChanged';

    // PLM-CFG-02 tax configuration changes.
    public const TAX_CONFIG_CHANGED = 'TaxConfigChanged';
}
