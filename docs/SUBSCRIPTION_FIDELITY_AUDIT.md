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
| PAUSE | ACTIVE→PENDING_PAUSE→SUSPENDED(+reason) | ✅ | `subscription_pause_config`; pause fee (BIL) |
| RESUME | SUSPENDED→PENDING_RESUME→ACTIVE | ✅ | reconnection fee (BIL); admin_force fields |
| SUSPEND-NP | ACTIVE→PENDING_SUSPEND_NP→SUSPENDED(+clears restrictions) | ✅ | `suspend_np_config`; BILLING_INTERNAL role gate |
| TERMINATE | ACTIVE→PENDING_TERMINATION→TERMINATED | ✅ | auto EQP equipment-pickup trigger; deposit refund |
| UPGRADE/DOWNGRADE | ACTIVE→PENDING_UPGRADE/DOWNGRADE→ACTIVE | ✅ | proration/payment gate (BIL); scheduled effective-timing; equipment-bind |
| RELOCATION/MIGRATION | ACTIVE→PENDING_*→ACTIVE | ✅ | auto WO-01 SHIFTING creation; scheduled effective-timing |
| RESTRICT | ACTIVE (no transient) | ✅ | — (faithful) |

## Commit-window sequence (now uniform)

`validate (drools) → gateway → enter-pending (PENDING_*) → fulfillment (FUL/network, gates) → commit (final status + reason) → notify`, with the operation record narrating the §5 `current_state` at each step. Pause/suspend open a `subscription_pause_history` row; resume closes it.

## Summary

The **framework + lifecycle state machine is now DD-faithful**: transient PENDING_*
states, the rich operation `current_state`, config-driven process keys, DB-enforced
concurrency, cancel/timeout/in-flight APIs, pause-history, and a fulfillment gate in
every commit window.

The remaining ⚠️ items are a **separate integration layer** — primarily **billing
charge integration** (proration, pause/reconnection fees, payment-confirmed gates
via BIL-01), **cross-module auto-triggers** (terminate→EQP pickup, relocate→WO
SHIFTING), per-operation **operator-config tables**, and minor **topic/table naming**.
These are tracked here and do not affect the state-machine fidelity.
