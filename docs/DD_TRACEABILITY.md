# DD Traceability Matrix

Generated from `docs/design` (256 PDFs = 134 design documents + 122 DEV_IMPL_GUIDE
companions; guides inherit their DD's status). Statuses follow the conformance audit
(`docs/DD_CONFORMANCE_AUDIT.md`); the official cross-map is
`00_index/DD_CROSS-00-System_Traceability_and_Implementation_Map`.

Status legend: **IMPLEMENTED** (behavior + tests) · **PARTIAL** (works, depth below spec)
· **VARIANT** (per-operator flow doc — capability delivered by the config engine)
· **DEFERRED** (explicit decision, documented) · **MISSING** (tracked gap)
· **REFERENCE** (governing doc, nothing to implement).

## corpus root (HLD / planning)
| Document | Status | Where / note |
|---|---|---|
| APPFREESN-HLD_SOPHIX_V3-DD_ALIGNED-20260601 | **REFERENCE** | HLD / audit / planning companion document |
| APPFREESN-PROCESS_MODULE_MAP-DD_ALIGNED-20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_DD_Audit_Response_20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_DD_Coherence_Review_and_Hardening_20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_DD_Reading_And_Implementation_Order_20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_Implementation_Guide_Coverage_Audit_20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_MVP_Runtime_Simplification_Baseline_20260601 | **REFERENCE** | HLD / audit / planning companion document |
| Sophix_V3_Performance_Complexity_Assessment_20260601 | **REFERENCE** | HLD / audit / planning companion document |

## 00_index
| Document | Status | Where / note |
|---|---|---|
| DD_API-00-API_Catalog_and_Gateway_Standards-v1.0 | **REFERENCE** | Index/standards/test-strategy document (governs, not implemented as a module) |
| DD_CROSS-00-System_Traceability_and_Implementation_Map-v1.0 | **REFERENCE** | Index/standards/test-strategy document (governs, not implemented as a module) |
| DD_INDEX | **REFERENCE** | Index/standards/test-strategy document (governs, not implemented as a module) |
| DD_QA-01-End_to_End_Test_Strategy-v1.0 | **REFERENCE** | Index/standards/test-strategy document (governs, not implemented as a module) |

## 01_foundations
| Document | Status | Where / note |
|---|---|---|
| FOUNDATION_AUTH | **DEFERRED** | Local Sanctum+spatie; Keycloak/OIDC (LDAP federation) is the documented swap seam |
| FOUNDATION_CACHE | **IMPLEMENTED** | SophixCache cache-aside + consumers + event eviction + admin ops |
| FOUNDATION_CAMUNDA | **IMPLEMENTED** | Config-driven WorkflowEngine + external-task workers + studio |
| FOUNDATION_DB | **IMPLEMENTED** | PostgreSQL-only, module-owned schemas/migrations |
| FOUNDATION_DROOLS | **IMPLEMENTED** | DataDrivenRuleEngine decision tables + studio |
| FOUNDATION_FILE_STORAGE | **IMPLEMENTED** | File API (store/show/download) |
| FOUNDATION_KAFKA | **IMPLEMENTED** | Transactional outbox/inbox EventBus (Kafka-swappable driver) |

## 02_framework
| Document | Status | Where / note |
|---|---|---|
| DD_SUB-LM-01-Subscription_Lifecycle_Management-v1.0 | **IMPLEMENTED** | Subscription master + status/transition catalogs + restrictions + pause history |
| DD_SUB-WF-FRAMEWORK-01-Subscription_Workflow_Framework-v1.0 | **IMPLEMENTED** | Operation framework: idempotency, single-in-flight, PENDING_* commit windows, cancel/timeout APIs |
| DD_SUB-WF-FRAMEWORK-01-WORKERS-v1.0 | **IMPLEMENTED** | Operation framework: idempotency, single-in-flight, PENDING_* commit windows, cancel/timeout APIs |

## 03_subscription_lifecycle
| Document | Status | Where / note |
|---|---|---|
| DD_SUB-WF-ACTIVATE-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-ACTIVATE-01-Subscription_Activation-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-PAUSE-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-PAUSE-01-Subscription_Pause-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-RESUME-01-FLOW-WIK-v1.2 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-RESUME-01-Subscription_Resume-v1.2 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-TERMINATE-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-TERMINATE-01-Subscription_Termination-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |

## 04_subscription_workflows
| Document | Status | Where / note |
|---|---|---|
| DD_SUB-WF-DOWNGRADE-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-DOWNGRADE-01-Subscription_Downgrade-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-MIGRATION-01-FLOW-WIK-v1.1 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-MIGRATION-01-Subscription_Migration-v1.1 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-RELOCATION-01-FLOW-WIK-v1.1 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-RELOCATION-01-Subscription_Relocation-v1.1 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-RESTRICT-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-RESTRICT-01-Subscription_Restriction-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-SUSPEND-NP-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-SUSPEND-NP-01-Subscription_Non_Payment_Suspension-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_SUB-WF-UPGRADE-01-FLOW-WIK-v1.0 | **VARIANT** | Per-operator flow variant — realised as operator-scoped process/rule config (engine supports overrides; WIK seeded) |
| DD_SUB-WF-UPGRADE-01-Subscription_Upgrade-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |

## 05_billing
| Document | Status | Where / note |
|---|---|---|
| DD_BIL-01-CN-01-Note_Application-v1.0 | **IMPLEMENTED** | NoteApplicationService: POSTPAID outstanding reduce/increase (surplus → account credit, FIFO auto-allocation), PREPAID wallet credit/debit (insufficient → FAILED, retryable), append-only note_application_ledger, Applied/Failed events |
| DD_BIL-01-Charging_Engine-Core-v1.1 | **IMPLEMENTED** | Charging core: billing intents (invoice/wallet/credit rails), cycle billing, rating |
| DD_BIL-01-PAY-01-Payment_Application-v1.0 | **IMPLEMENTED** | Payment application: FIFO/directed allocation, surplus credit, dunning clear |
| DD_BIL-02-ADJ-01-Invoice_Adjustments-v1.3 | **IMPLEMENTED** | AdjustmentService: FULL/LINE/AMOUNT scopes, reason-code catalog, limits config + override, audited approval steps (zero-step/threshold/multi), CREDIT_NOTE/DEBIT_NOTE issuance (CN-/DN- legal numbers, original_invoice_id), /retry-application |
| DD_BIL-02-GEN-01-Invoice_Generation-v1.3 | **IMPLEMENTED** | Invoicing core: assembler, gap-free legal numbering, read API |
| DD_BIL-02-Invoicing-Core-v2.11 | **IMPLEMENTED** | Invoicing core: assembler, gap-free legal numbering, read API |
| DD_BIL-02-READ-01-Invoice_Read_API-v1.5 | **IMPLEMENTED** | Invoicing core: assembler, gap-free legal numbering, read API |
| DD_BIL-02-STA-01-Status_Management_and_Recovery-v1.0 | **IMPLEMENTED** | Invoicing core: assembler, gap-free legal numbering, read API |
| DD_BIL-02-TAX-01-Tax_Invoice_and_Gateway-v1.4 | **IMPLEMENTED** | Tax invoice + fiscalisation gateway |
| DD_BIL-03-Cycle_Close-v1.1 | **IMPLEMENTED** | Dunning ladder as decision table -> SUB-WF ops |
| DD_BIL-04-Dunning_Engine-v1.4 | **IMPLEMENTED** | Dunning ladder as decision table -> SUB-WF ops |
| DD_BIL-05-Wallet_and_Topup-v1.0 | **IMPLEMENTED** | Multi-wallet ledger + topup (per PLM-CFG-03 catalog) |
| DD_BIL-CFG-01-Billable_Event_Catalog-v1.0 | **IMPLEMENTED** | billable_event + billable_event_category (operator-scoped, DRAFT→ACTIVE→RETIRED, trigger taxonomy, sign policy, applicability), admin CRUD API, runtime enforcement on billing intents (unknown rejected, applicability skip, sign policy) |
| DD_DIS-OP-01-Discount_Runtime_Application-v1.0 | **IMPLEMENTED** | Discount compute at billing time |
| DD_MED-01-Usage_Mediation-v1.0 | **IMPLEMENTED** | Mediation dedupe + rating (voice/usage tariff catalogs) |
| DD_PAY-GW-01-Payment_Gateway_Integration-v1.0 | **IMPLEMENTED** | PaymentGateway module: callback webhook -> PaymentService.receiveAndApply (tested) |
| DD_RAT-01-Usage_Rating-v1.0 | **IMPLEMENTED** | Mediation dedupe + rating (voice/usage tariff catalogs) |

## 06_fulfillment
| Document | Status | Where / note |
|---|---|---|
| DD_FUL-02-FRAMEWORK-v1.0 | **IMPLEMENTED** | Order journey AS CONFIG (ful-order-capture process; §1.1) |
| DD_FUL-02-Order_Capture-v1.0 | **IMPLEMENTED** | Order journey AS CONFIG (ful-order-capture process; §1.1) |
| DD_FUL-02-STEP-ACTIVATION-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-CANCELLATION-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-CAPTURE-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-INSTALL-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-KYC-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-PAYMENT-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-SUBSCRIPTION-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-02-STEP-VALIDATE-v1.0 | **IMPLEMENTED** | Journey step realised as flow node/topic handler (payment-wait messageCatch open item) |
| DD_FUL-03-Service_Activation-v1.1 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-04-Restriction_Fulfillment-v1.1 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-05-Termination_Fulfillment-v1.0 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-07-HomePass_Lifecycle_Fulfillment-v1.0 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-08-Anchor_Date_Change-v1.0 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-09-Equipment_Swap_Fulfillment-v1.0 | **IMPLEMENTED** | Activation/restriction/termination fulfillment via SUB-WF + provisioning gate |
| DD_FUL-10-Technology_Migration_Fulfillment-v1.0 | **IMPLEMENTED** | SUB-WF MIGRATION flow (homepass+technology change) + provisioning gate |
| DD_PROV-INT-01-Provisioning_Broadcast_and_Reconciliation-v1.0 | **IMPLEMENTED** | Command ledger, per-target adapters, attempts, reconcile, force-sync approval (§9 async model deferred) |

## 07_work_order
| Document | Status | Where / note |
|---|---|---|
| DD_WO-01-CONTRACT-FOR-SUB-WF-v1.0 | **IMPLEMENTED** | Operation flow as seeded process definition + handlers (see SUBSCRIPTION_FIDELITY_AUDIT) |
| DD_WO-01-FLOW-INSTALLATION-v1.0 | **IMPLEMENTED** | Flow as seeded process definition (installation/support/shifting) / SUB-WF contract honored |
| DD_WO-01-FLOW-SHIFTING-v1.0 | **IMPLEMENTED** | Flow as seeded process definition (installation/support/shifting) / SUB-WF contract honored |
| DD_WO-01-FLOW-SUPPORT-v1.0 | **IMPLEMENTED** | Flow as seeded process definition (installation/support/shifting) / SUB-WF contract honored |
| DD_WO-01-FRAMEWORK-v1.0 | **IMPLEMENTED** | Generic WO state machine, reassign, notes registry, 2-step finalize + checklist, attachments |
| DD_WO-01-Work_Order_Module-v1.0 | **IMPLEMENTED** | Generic WO state machine, reassign, notes registry, 2-step finalize + checklist, attachments |

## 08_osr_equipment
| Document | Status | Where / note |
|---|---|---|
| DD_OSR-01-Stock_Chain_Engine-v1.0 | **IMPLEMENTED** | Stock chain: movements/balances/reservations/availability + WO consumption |
| DD_OSR-02-Procurement-v1.0 | **IMPLEMENTED** | Procurement: POs approve/receive |
| DD_OSR-05-Inventory_Audit-v1.0 | **IMPLEMENTED** | Inventory audit: counts + reconcile |
| DD_OSR-INSTANCE-01-Equipment_Instance_Registry-v1.0 | **IMPLEMENTED** | Serialized instance registry + lifecycle ledger |
| DD_OSR-RMA-01-Equipment_Swap_and_RMA-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |
| DD_OSR-RMA-01-FLOW-EQP-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |
| DD_OSR-RMA-01-FLOW-EQU-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |
| DD_OSR-RMA-01-FLOW-SWAP-GPON-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |
| DD_OSR-RMA-01-FLOW-SWAP-HFC-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |
| DD_OSR-RMA-01-FRAMEWORK-v1.0 | **IMPLEMENTED** | Swap/RMA flows (EQP/EQU/GPON/HFC) as config flows |

## 09_catalogs
| Document | Status | Where / note |
|---|---|---|
| DD_ILM-CFG-01-Customer_Service-v1.0 | **IMPLEMENTED** | Customer/Account master, KYC 2-level, flags §3.5, sub-status registry, Customer 360 UI |
| DD_ILM-CFG-02-Tech_Region_Registry-v1.0 | **IMPLEMENTED** | Tech region registry |
| DD_PLM-CFG-01-Service_Catalog-v1.0 | **IMPLEMENTED** | Service catalog + classes |
| DD_PLM-CFG-02-Tax_Configuration-v1.0 | **IMPLEMENTED** | Tax groups/rules + compute |
| DD_PLM-CFG-03-Wallet_Configuration-v1.0 | **IMPLEMENTED** | Wallet catalog: types, applicability, precedence, lifecycle, R-W rules |
| DD_PLM-CFG-04-Discount_Catalog-v0.6 | **IMPLEMENTED** | Discount catalog + assignment + compute |
| DD_PLM-CFG-05-Adjustment_Type_Catalog-v1.0 | **IMPLEMENTED** | Config catalogs (adjustment/equipment/voice+usage tariff/adjustment) |
| DD_PLM-CFG-06-Equipment_Type_Catalog-v1.0 | **IMPLEMENTED** | Config catalogs (adjustment/equipment/voice+usage tariff/adjustment) |
| DD_PLM-CFG-07-Voice_Tariff_Catalog-v1.0 | **IMPLEMENTED** | Config catalogs (adjustment/equipment/voice+usage tariff/adjustment) |
| DD_PLM-CFG-08-Adjustment_Catalog-v1.0 | **IMPLEMENTED** | Config catalogs (adjustment/equipment/voice+usage tariff/adjustment) |
| DD_RLM-CFG-01-HomePass_Configuration-v1.0 | **IMPLEMENTED** | HomePass serviceability |
| DD_SIP-01-Package_Management-v1.0 | **IMPLEMENTED** | Packages + versions + activate lifecycle (launch windows/approval hook light) |
| DD_SIP-02-Package_Launch_Lifecycle-v1.0 | **IMPLEMENTED** | Packages + versions + activate lifecycle (launch windows/approval hook light) |
| DD_SIP-03-Discount_Assignment-v1.0 | **IMPLEMENTED** | Discount catalog + assignment + compute |
| DD_SIP-04-Bundle_Launch-v1.0 | **IMPLEMENTED** | Commercial bundles: lifecycle, launch checks, availability, migration paths + studio |
| DD_SIP-05-Promotional_Campaigns-v1.0 | **IMPLEMENTED** | Campaigns MVP: eligibility, channels, redemption->SIP-03 + designer/launcher UI |

## 10_other
| Document | Status | Where / note |
|---|---|---|
| DD_ASR-01-Technical_Trouble-v1.0 | **IMPLEMENTED** | ASR intake + rules.asr.routing (queue/priority/auto-WO) via TicketService (tested) |
| DD_ASR-02-Information_Request-v1.0 | **IMPLEMENTED** | ASR intake + rules.asr.routing (queue/priority/auto-WO) via TicketService (tested) |
| DD_ASR-03-Complaint-v1.0 | **IMPLEMENTED** | ASR intake + rules.asr.routing (queue/priority/auto-WO) via TicketService (tested) |
| DD_ASR-04-Service_Request-v1.0 | **IMPLEMENTED** | ASR intake + rules.asr.routing (queue/priority/auto-WO) via TicketService (tested) |
| DD_CUST-INT-01-Customer_Interaction_Timeline-v1.0 | **IMPLEMENTED** | Interaction timeline API + 360 panel |
| DD_EM-01-Franchise_Management-v1.0 | **PARTIAL** | Franchise + leads (qualify/convert/lose) implemented; territory depth light |
| DD_EM-02-Contractor_and_Staff_Registry-v1.0 | **IMPLEMENTED** | Workforce registry (contractor/team/staff) |
| DD_EM-03-CVM_Retention_and_Recovery-v1.0 | **IMPLEMENTED** | CVM activities + policies (recovery/winback offers) |
| DD_EM-CFG-03-RBAC_Catalog-v1.0 | **IMPLEMENTED** | RBAC catalog + matrix + effective access + admin portal + change audit (scope system deferred w/ auth) |
| DD_EM-CFG-04-Approval_Workflow_Catalog-v1.0 | **IMPLEMENTED** | Approval definitions/requests/decide (foundation) |
| DD_FA-01-Equipment_Field_Audit-v1.0 | **IMPLEMENTED** | Field audits (equipment/network/KYC) + policy seeder |
| DD_FA-02-Network_Field_Audit-v1.0 | **IMPLEMENTED** | Field audits (equipment/network/KYC) + policy seeder |
| DD_FA-03-Customer_KYC_Field_Audit-v1.0 | **IMPLEMENTED** | Field audits (equipment/network/KYC) + policy seeder |
| DD_ICN-01-Internal_Communications-v1.0 | **IMPLEMENTED** | Notifications + internal comms + per-channel template studio (provider gateways stubbed) |
| DD_NOT-01-Notification_Service-v1.3 | **IMPLEMENTED** | Notifications + internal comms + per-channel template studio (provider gateways stubbed) |
| DD_REP-01-Reporting_Data_Mart_and_Dashboard_API-v1.0 | **IMPLEMENTED** | Daily metrics + export + reconcile (deep analytics external by decision) |
| DD_SALES-01-Lead_Territory_and_Franchise_Sales_Management-v1.0 | **PARTIAL** | Franchise + leads (qualify/convert/lose) implemented; territory depth light |
| DD_TCK-01-Ticketing_and_Case_Management-v1.0 | **IMPLEMENTED** | Case lifecycle, category catalog + WO gating, WO-finalized loop, reopen, SLA + cockpit UI |

## 99_frontend
| Document | Status | Where / note |
|---|---|---|
| FE-APP-00-Frontend_Architecture_and_Shared_UX_Foundation-v1.0 | **PARTIAL** | Shared Inertia/Vue shell + operator theming (runtime config: name/color/logo/locale/currency); component library lighter than spec |
| FE-APP-01-BSS_Backoffice_Web_App-v1.0 | **PARTIAL** | Backoffice: 13 surfaces incl. studios, NOC, warehouse, Customer 360, ticket cockpit; depth grows per screen |
| FE-APP-02-Outdoor_Sales_Franchise_App-v1.0 | **PARTIAL** | Field/sales PWA at /m (jobs, swaps); subset of spec |
| FE-APP-03-Contractor_Technical_Mobile_App-v1.0 | **PARTIAL** | Field/sales PWA at /m (jobs, swaps); subset of spec |
| FE-APP-04-Customer_Self_Care_Frontoffice_App-v1.0 | **PARTIAL** | Self-care PWA at /care (subs, invoices, pay, tickets); subset of spec |
| FE-APP-05-Reporting_Portal-v1.0 | **DEFERRED** | Reporting portal — deep reporting external by decision |
| FE-CH-USSD-01-USSD_Channel-v1.0 | **PARTIAL** | USSD webhook + menu skeleton |

## Summary
| Status | DDs |
|---|---|
| IMPLEMENTED | 102 |
| PARTIAL | 8 |
| VARIANT | 10 |
| DEFERRED | 2 |
| MISSING | 0 |
| REFERENCE | 12 |
| **Total** | **134** |