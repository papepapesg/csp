# Ticketing

Customer-service case capability for ticket lifecycle, categories, SLA, assignment, notes, attachments, links and specialized assurance flows.

## Use

Create and manage tickets through `routes/api.php`. Review queues and individual timelines with `sophix:ticketing:*` commands; use service methods for assignment and transitions.

## Configure

Categories, priorities, statuses, SLA policies, routing and assurance types are catalogs/configuration. Ticket timelines are append-only operational history.

## Extend

Model specialized flows as handlers/workflows selected by category configuration. Keep cross-module remediation behind APIs/events and add SLA, authorization and idempotency tests.

## Exposed APIs

- `GET sla-policies`
- `GET tickets`
- `GET tickets/{ticket}`
- `POST asr`
- `POST sla-policies`
- `POST tickets`
- `POST tickets/{ticket}/assign`
- `POST tickets/{ticket}/attachments`
- `POST tickets/{ticket}/cancel`
- `POST tickets/{ticket}/close`
- `POST tickets/{ticket}/comments`
- `POST tickets/{ticket}/links`
- `POST tickets/{ticket}/reopen`
- `POST tickets/{ticket}/resolve`
- `POST tickets/{ticket}/work-orders`

## Data models

- `SlaPolicy`
- `Ticket`
- `TicketCategory`
- `TicketComment`
- `TicketTimeline`

## Services

- `AsrService`
- `TicketService`

## Events

- `ResolveTicketOnWorkOrderFinalized`
- `TicketEvents`
- `TicketEvents::ASSIGNED`
- `TicketEvents::CANCELLED`
- `TicketEvents::CLOSED`
- `TicketEvents::CREATED`
- `TicketEvents::REOPENED`
- `TicketEvents::RESOLVED`
- `TicketEvents::TOPIC`
- `TicketEvents::WORK_ORDER_LINKED`

## Commands

- `sophix:ticketing:ops-status`
- `sophix:ticketing:ticket-reassign`
- `sophix:ticketing:ticket-show`

## Test

Core ticket and assurance scenarios are in `tests/Feature`.
