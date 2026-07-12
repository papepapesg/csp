# Ticketing

Customer-service case capability for ticket lifecycle, categories, SLA, assignment, notes, attachments, links and specialized assurance flows.

## Use

Create and manage tickets through `routes/api.php`. Review queues and individual timelines with `sophix:ticketing:*` commands; use service methods for assignment and transitions.

## Configure

Categories, priorities, statuses, SLA policies, routing and assurance types are catalogs/configuration. Ticket timelines are append-only operational history.

## Extend

Model specialized flows as handlers/workflows selected by category configuration. Keep cross-module remediation behind APIs/events and add SLA, authorization and idempotency tests.

## Test

Core ticket and assurance scenarios are in `tests/Feature`.
