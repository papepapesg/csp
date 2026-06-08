# Subscription Workflow Fidelity Audit

Audited against `DD_SUB-WF-FRAMEWORK-01`, `DD_SUB-LM-01`, and the per-operation DDs
(PAUSE/RESUME/SUSPEND-NP/TERMINATE/UPGRADE/DOWNGRADE/RELOCATION/MIGRATION/RESTRICT).
Legend: ✅ implemented · ⚠️ partial/divergent (tracked).

## SUB-WF-FRAMEWORK-01

| Requirement | Status | Notes |
|---|---|---|
| `subscription_operation` ledger | ✅ | idempotency, concurrency, correlation |
| `current_state` §5 vocabulary | ✅ | INITIATED→VALIDATING→PENDING_STATE_FLIP→FULFILLMENT_CALL→COMMITTING_FINAL_STATE→COMPLETED narrated by handlers |
| `final_state` COMPLETED/FAILED/CANCELLED | ✅ | in-flight == final_state IS NULL |
| `subscription_operation_config` §6.3 + R-FW-7 process-key resolution | ✅ | per operator+kind; convention fallback; disabled-kind reject |
| R-FW-1 one in-flight state-changing op | ✅ | query + partial unique index |
| R-FW-2 one RESTRICT concurrent | ✅ | non-exclusive + restrict partial index |
| R-FW-4/5 idempotency (operator+key, hash mismatch) | ✅ | foundation idempotency middleware + ledger key |
| R-FW-6 async 202 + operationId | ✅ | ApiResponse::accepted |
| R-FW-8 bpmn_process_instance_id set after start | ✅ | |
| R-FW-9/10 timeout → REVERTING → FAILED OPERATION_TIMEOUT | ✅ | `sophix:subscription:operation-timeouts` sweep + revert |
| R-FW-12 outbox events | ✅ | transactional outbox |
| §8.2 cancel / §8.3 status / §8.4 in-flight / §8.5 history APIs | ✅ | cancel reverts PENDING_* + emits SubscriptionOperationCancelled |
| R-FW-3 TERMINATE interrupts an in-flight op via cancel message | ⚠️ | manual cancel exists; terminate does not yet auto-interrupt a running op |
| §9 framework events on `sophix.subscription.operation.*` topic | ⚠️ | emitted (Started/Completed/Failed/Cancelled) on the `subscription.lifecycle` topic; topic name differs |
| kafka_outbox table | ⚠️ | uses the foundation `outbox_events` (functionally equivalent, swappable) |
| §10 FOUNDATION_AUTH | ⚠️ | local Sanctum + spatie (top known divergence) |

## SUB-LM-01

| Requirement | Status |
|---|---|
| subscription master + all fields | ✅ |
| `subscription_status_code` §5.2 catalog (incl. transient PENDING_*) | ✅ |
| `subscription_transition_reason` §5.3 catalog | ✅ |
| `subscription_restriction` §5.4 catalog + active_restrictions shape | ✅ |
| PENDING_* transient states held during the commit window | ✅ proven by test |

## Per-operation state machines

| Op | Transition | Status | Remaining gap |
|---|---|---|---|
| PAUSE | ACTIVE→PENDING_PAUSE→SUSPENDED(+reason) | ✅ | `subscription_pause_config` seeded (catalog only — self-service/min-duration gating not yet consumed in validate); pause fee wired (BIL-01 PAUSE_FEE) |
| RESUME | SUSPENDED→PENDING_RESUME→ACTIVE | ✅ | reconnection fee wired (BIL-01); admin_force fields |
| SUSPEND-NP | ACTIVE→PENDING_SUSPEND_NP→SUSPENDED(+clears restrictions) | ✅ | `subscription_suspend_np_config` + BILLING_INTERNAL role gate + dunning-context on pause-history + `debtAmountTier` — done |
| TERMINATE | ACTIVE→PENDING_TERMINATION→TERMINATED | ✅ | auto EQP equipment-pickup wired (OSR-RMA); deposit refund |
| UPGRADE/DOWNGRADE | ACTIVE→PENDING_UPGRADE/DOWNGRADE→ACTIVE | ✅ | proration/pay-first gate wired (BIL-01); scheduled effective-timing; equipment-bind |
| RELOCATION/MIGRATION | ACTIVE→PENDING_*→ACTIVE | ✅ | auto WO-01 SHIFTING creation wired (relocation); scheduled effective-timing |
| RESTRICT | ACTIVE (no transient) | ✅ | — (faithful) |

## Commit-window sequence (now uniform)

`validate (drools) → gateway → enter-pending (PENDING_*) → fulfillment (FUL/network, gates) → commit (final status + reason) → notify`, with the operation record narrating the §5 `current_state` at each step. Pause/suspend open a `subscription_pause_history` row; resume closes it.

## Billing & cross-module integration layer (now closed)

| Capability | Status | Notes |
|---|---|---|
| BIL-01 billing-intent (`billing_intent`) emitted in the commit window | ✅ | `sub.billing-intent` step; fee invoice (>0) or account credit (<0) |
| Pay-first gate (UPGRADE proration) | ✅ | parks on `AWAITING_PAYMENT` (messageCatch `sub-payment-confirmed`); InvoicePaid → listener confirms intent + correlates → resumes commit |
| Pause fee / reconnection fee intents | ✅ | PAUSE_FEE (pay-first false), RECONNECTION_FEE |
| Terminate → OSR-RMA EQP equipment pickup | ✅ | `sub.trigger-equipment-pickup` raises an EQP swap-request per field-active device |
| Relocation → WO-01 SHIFTING work order | ✅ | `sub.create-shifting-wo` before the network call; `beforeFulfil` hook |
| Per-operation operator-config tables | ✅ | `subscription_pause_config`, `subscription_suspend_np_config` seeded for all operators |

## Summary

The **framework + lifecycle state machine is DD-faithful**: transient PENDING_*
states, the rich operation `current_state`, config-driven process keys, DB-enforced
concurrency, cancel/timeout/in-flight APIs, pause-history, and a fulfillment gate in
every commit window. The **billing + cross-module integration layer is now wired**:
BIL-01 billing-intents with a pay-first gate, terminate→EQP pickup, relocation→WO
SHIFTING, and the per-operation operator-config catalogs.

Remaining ⚠️ items are narrow and tracked above: **FOUNDATION_AUTH** (local Sanctum +
spatie instead of Keycloak — top known divergence), **framework event topic naming**
(`subscription.lifecycle` vs `sophix.subscription.operation.*`), **`outbox_events` vs
`kafka_outbox`** (functionally equivalent), R-FW-3 **terminate auto-interrupt** of an
in-flight op, **scheduled effective-timing** (IMMEDIATE only today), and deeper
**consumption** of the pause config (self-service / min-duration gating in validate).
None affect the state-machine fidelity.
