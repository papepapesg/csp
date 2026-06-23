# Wananchi Kenya (Zuku) — Country Deployment

A complete **Kenya country deployment** of the generic SOPHIX BSS, built **entirely
by configuration + seed data**. No platform or module code is changed — the same
binary serves every operator; Kenya is data.

External connectors (M-Pesa, KRA fiscal signer, AutoSend, NMS/OLT/CMTS/Verimatrix/
VoipSwitch) remain **stubs**; at go-live each is swapped behind its existing adapter
seam with no code change.

## Run it

```bash
# migrate a clean schema, then seed the Kenya deployment
php artisan migrate:fresh --force
php artisan db:seed --class="Database\\Seeders\\Kenya\\KenyaDeploymentSeeder" --force
```

Admin user: `admin@zuku.co.ke` / `password` (operator `WIK`).

## What it seeds

Orchestrated by [`KenyaDeploymentSeeder`](../../database/seeders/Kenya/KenyaDeploymentSeeder.php):

1. **Platform configuration** (the operator-agnostic engine catalogs): RBAC, operator
   config, subscription status + operation catalogs, **workflow process definitions**,
   **rules decision tables**, tax cascade, wallet catalog, dunning programs, work-order
   framework + KE job-type catalog, OSR, ticketing/SLA/ASR, notification templates, USSD.
   Demo/POC fixtures (Senegal dispatcher, demo journeys, demo catalog) are excluded.

2. **Kenya (Wananchi/Zuku) configuration:**
   | Seeder | Configures |
   | --- | --- |
   | `KenyaTaxSeeder` | WIK-scoped `rules.tax-applicability`: **service taxed** (SUBSCRIPTION/PACKAGE/INTERNET/TV → KRA `WIK_INTERNET`), **usage exempt** (VOICE/DATA/SMS → `NONE`) |
   | `KenyaCommercialCatalogSeeder` | Zuku service classes, services, **single/double/triple-play packages** (KES, 30-day cycle), bundles, **dual-wallet routing** (Internet/TV→`MONEY_KES`, Voice→`VOICE_KES`) |
   | `KenyaTechRegionSeeder` | Kenya tech-region hierarchy (Nairobi + neighbourhoods, Mombasa, Kisumu, Nakuru) + 10 **SERVICEABLE** home passes (mixed GPON/HFC) |
   | `KenyaBillingConfigSeeder` | **Dual-wallet invoice grouping** (`CYCLE_*` → `WALLET`): one invoice per wallet |
   | `KenyaProvisioningSeeder` | Adds the **Verimatrix TV** plane (GPON/HFC/SIP already seeded) — all on the stub adapter |
   | `KenyaSampleCustomersSeeder` | A live CAS sample: 3 customers→accounts→subscriptions, service classes (VIP/Platinum/Gold), **ANNIVERSARY cycles** (anchor 1–28, 30-day), a **triple-play** subscriber and an **NPD-flagged** account |

## How the Confluence AS-IS maps to platform configuration

Every Wananchi specific is achieved by config — verified against the running BSS:

| Wananchi rule (Confluence) | Configured by | Verified result |
| --- | --- | --- |
| Tax on service amount (KRA excise 15% + VAT 16%) | `WIK_INTERNET` tax group + WIK tax-applicability rule | SUBSCRIPTION 8 999 → tax **3 005.67** (excise 1 349.85 + VAT 1 655.82) |
| No tax on usage | tax-applicability `VOICE/DATA/SMS → NONE` | VOICE 500 → tax **0** (`EXEMPT_BY_RULE`) |
| Proration `paid ÷ (30 × BILL_FREQUENCY)` | `cycle_period_days = 30` (×freq → 30/180/360) | 10-day partial of 8 999 → **2 999.67** |
| 28 anniversary cycles, Day-25 pro forma | `cycle_model = ANNIVERSARY`, `cycle_anchor_day` (1–28); `ProFormaService` 5-day window | anchors 5 / 12 / 20 seeded |
| Dual wallet (Internet/TV vs Voice) | service `default_wallet_ref` + `invoice_grouping_config = WALLET` | triple-play routes Voice→`VOICE_KES`, Internet/TV→`MONEY_KES`; one invoice per wallet |
| Account number = sole operational key | `customer_account.account_number` (unique per operator) | `002-XXXXXXXT` |
| Lifecycle + sub-status; NPD independent flag | string status + `customer_sub_status_catalog`; `customer_account_flag` `NPD` | NPD flag on a Gold account, independent of status |
| Service class informational (VIP/Platinum/Gold/STAFF) | `customer_account.service_class_1` (no billing impact) | seeded on the sample accounts |
| HFC + GPON network; TV + Voice planes | provisioning targets GPON/HFC/SIP/TV → **stub** | 5 targets (swap adapter at go-live) |
| New flows / rules without code | `process_definition` rows + `decision_table` rows | platform workflow + rules catalogs |

## Swapping stubs for real connectors (at go-live)

No code change — replace the binding/driver:

- **Provisioning:** set each `provisioning_adapter_config.adapter_class` to the vendor
  adapter (Huawei NCE, Casa/Clearcable NOMS, VoipSwitch, Verimatrix).
- **M-Pesa / SMS / Tax signer:** `SOPHIX_SMS_DRIVER`, `SOPHIX_TAX_DRIVER`, and the
  `tax.signer_implementations` / `notification.adapter_implementations` registries.
- **Auth:** `SOPHIX_AUTH_DRIVER=keycloak` (env only).

## Notes

- Idempotent: every seeder uses `updateOrCreate` / `updateOrInsert` — safe to re-run.
- Operator code for Kenya is **`WIK`** (Wananchi Kenya), KES, `Africa/Nairobi`, locale `en`.
