# SOPHIX Engines — where the "Java stack" lives (all Laravel-native)

Per the "all Laravel" decision, there are **no Camunda/Drools/Kafka dependencies**.
Each is a Laravel-native, **config-driven** engine. Engines are CODE; their content
(flows, rules) is DATA in the database, editable in the Backoffice — so a new
market/operator is a configuration change, not a redeploy.

## Workflow engine (replaces Camunda) — `Modules/Workflow`
| Concern | Location |
| ------- | -------- |
| Engine (code) | `Modules/Workflow/app/Engine/WorkflowEngine.php`, `TaskRegistry.php` |
| External-task worker | `app/Console/WorkflowWorkerCommand.php` (`php artisan sophix:workflow:work`) |
| Flows (DATA) | `process_definition` table · seed `Modules/Workflow/database/seeders/ProcessDefinitionSeeder.php` |
| Reusable steps (toolbox) | `*/app/Workflow/*Handler.php` implementing `TaskHandler` (topics: `sub.activate`, `provisioning.activate-service`, `notify.send`, `rules.evaluate`, …) |
| UI | **Studio** `/workflow/studio` (author/deploy) · **IT-Ops** `/workflow/ops` (live trace) |
| Wired into | `Modules/Subscription/app/Services/OperationFramework.php` → `$engine->start('sub-activate', …)` |
| Swap to real Camunda | `SOPHIX_WORKFLOW_DRIVER=camunda` (external-task REST, same worker pattern) |

## Rules engine (replaces Drools) — `Modules/Rules`
| Concern | Location |
| ------- | -------- |
| Engine (code) | `Modules/Rules/app/Engine/DataDrivenRuleEngine.php` (bound to `App\Foundation\Rules\RuleEngine`) |
| Rules (DATA) | `decision_table` table · seed `Modules/Rules/database/seeders/DecisionTableSeeder.php` |
| UI | **Rules Studio** `/rules/studio` (edit when→then, deploy version, test facts) |
| API | `/api/rules/decision-tables`, `/api/rules/{ruleSet}/evaluate` |
| Wired into | `Modules/Subscription/app/Workflow/ValidateActivationHandler.php` evaluates `activation.eligibility`; the sub-activate flow's "eligible?" gateway branches on it |
| Swap to real Drools | `SOPHIX_RULES_DRIVER=drools` (KIE server client) |

## Events (replaces Kafka) — `app/Foundation/Events`
Transactional outbox/inbox (`outbox_events`/`inbox_events`) + `EventBus`
(`OutboxEventBus`); dispatch via `php artisan sophix:outbox:dispatch`. Swap with
`SOPHIX_EVENT_BUS=kafka`.

## Provisioning/NMS (PROV-INT-01) — `Modules/Provisioning`
`ProvisioningAdapter` + `StubProvisioningAdapter` (swap via `SOPHIX_PROVISIONING_DRIVER`);
command ledger `provisioning_command`. Tax: `Modules/Billing` `TaxGateway` + `StubTaxGateway`.
