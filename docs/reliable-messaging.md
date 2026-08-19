# Nexzan Reliable Messaging Runbook

## Delivery contract

Nexzan uses RabbitMQ for cross-service delivery and Redis/Horizon only for local job execution.

```text
business transaction -> outbox_events -> confirmed RabbitMQ publish
RabbitMQ delivery -> inbox_events commit -> ACK -> Redis/Horizon job
Redis/Horizon job -> domain transaction + inbox completion
```

Delivery is at-least-once. A service-wide unique `event_id`, the Inbox unique constraint, and the domain write/Inbox completion transaction make local database effects effectively-once. `inbox_events` is the only RabbitMQ-consumer idempotency ledger; the legacy `processed_events` table is dropped by the shared package migration.

An ACK means the envelope is durably stored in `inbox_events`; it no longer means that only a Redis job was accepted. Invalid envelopes are rejected to the broker DLQ. Database failures are requeued. Unknown exchange/event combinations fail the Inbox job and follow Inbox backoff/dead handling.

## Envelope and publishing

Producers call `OutboxEvent::record()` or the `recordOutboxEvent()` compatibility facade inside the same database transaction as the business write. Both APIs only create an Outbox row; application producers never publish directly to RabbitMQ. The Outbox worker builds this envelope:

- `event`, `event_id`, `version`, `resource`, `occurred_at`, `producer`
- `aggregate_type`, `aggregate_id`, and `aggregate_version` for ordered aggregate streams

When an aggregate type and ID are supplied, the shared package allocates a monotonic version under a database row lock in the same transaction. Consumers complete stale or duplicate aggregate versions without repeating domain work.

`publishEnvelope()` uses persistent messages, `mandatory=true`, publisher confirms, and throws on returns, NACKs, missing confirms, and timeout. It is infrastructure-only and is called by the Outbox publisher (plus the inspected DLQ replay command), not by application producers.

## Permanent v2 topology

Every v2 queue is durable, uses exact bindings, and declares a matching dead-letter queue. Existing queues are not redeclared with new arguments because RabbitMQ rejects incompatible declarations.

| Service | Queue | Exact routing keys |
|---|---|---|
| Gateway | `gateway.project.v2.queue` | `project.resources_moved` |
| Gateway | `gateway.server.v2.queue` | `server.created`, `server.ready`, `server.deleted` |
| Gateway | `gateway.site.v2.queue` | `site.ready`, `site.update`, `site.delete` |
| Gateway | `gateway.team.v2.queue` | `team.billing_status.updated`, `team.grace_period.updated` |
| Atom | `atom.project.v2.queue` | `project.created`, `project.updated`, `project.user_default`, `project.deleted`, `project.resources_move_requested` |
| Atom | `atom.site.v2.queue` | `site.ready`, `site.update`, `site.delete` |
| Atom | `atom.team.v2.queue` | `team.created`, `team.member_added`, `team.member_remove`, `team.account_status.updated`, `team.billing_status.updated`, `team.billing_suspension.cleanup_requested`, `team.grace_period.updated` |
| Atom | `atom.user.v2.queue` | `user.created` |
| Billing | `billing.server.v2.queue` | `server.created`, `server.deleted`, `server.scaled`, `server.ready` |
| Billing | `billing.team.v2.queue` | `team.created`, `team.account_status.updated` |
| Billing | `billing.user.v2.queue` | `user.created` |
| Billing | `billing.volume.v2.queue` | `volume.created`, `volume.scaled`, `volume.deleted` |
| Site | `site.server.v2.queue` | `server.created`, `server.ready`, `server.status_update`, `server.deleted` |
| Site | `site.team.v2.queue` | `team.created`, `team.account_status.updated`, `team.billing_status.updated`, `team.grace_period.updated`, `team.billing_suspension.cleanup_requested` |
| Site | `site.user.v2.queue` | `user.created` |

The DLQ name is `<queue>.dlq`. The dead-letter exchange is `<domain exchange>.dlx`, and the dead-letter routing key is the DLQ name.

## Deployment and cutover

1. Release and tag `nexzan-shared`, update each service Composer lock, then run each service migration.
2. Deploy application code and Horizon configuration before starting Rabbit consumers.
3. Run each `consume:<domain> --declare-only` command once to create the v2 queues, bindings, and DLQs without consuming. Let v2 queues buffer while legacy consumers drain.
4. Before deploying the Inbox-ID-only job, drain every old Rabbit queue and every legacy Horizon job. The final implementation does not accept a raw legacy message payload.
5. Stop legacy Rabbit consumers after their broker queues reach zero; then make v2 consumers authoritative.
6. Start the Outbox worker. All application producers already record Outbox rows; there is no direct-publish feature flag or fallback.
7. Retire legacy queues only after queue depth, unacked count, and old Horizon jobs remain zero for the agreed window.

The unsupported `server.installation_failed` publisher was retired instead of creating permanently unroutable Outbox rows. Audit live bindings before reintroducing it, and add an exact binding plus handler first.

## Required processes

Each service runs:

- Horizon
- Laravel scheduler
- one `php artisan outbox:work --batch=100 --sleep=3`
- one `php artisan consume:<domain>` process for every consumed domain

Both recovery commands are registered every minute with `withoutOverlapping()->onOneServer()`:

```shell
php artisan outbox:recover
php artisan inbox:recover --batch=100
```

Dead rows are never pruned automatically. Inspect the payload, error, handler state, and downstream effects before retrying:

```shell
php artisan outbox:retry-dead <event-id>
php artisan inbox:retry-dead <event-id>
php artisan rabbitmq:dlq:retry <exchange> <base-queue> --limit=100
```

`--discard-invalid` on `rabbitmq:dlq:retry` permanently removes invalid messages and must only be used after inspection.

## Monitoring

Alert on:

- oldest pending/failed/dead Outbox and Inbox age
- counts by Outbox/Inbox status and retry attempts
- broker queue depth, unacked messages, DLQ depth, publisher returns/NACKs, and consumer count
- stale publishing/queued/processing leases
- Horizon failed jobs and process health

Do not prune unresolved rows. Retention requires a separate reviewed policy.

## Final-state guarantees and boundary

All audited Gateway, Atom, Billing, and Site RabbitMQ producers use the Outbox. Rabbit handlers receive only an Inbox primary ID, and `processed_events` is removed. Exact v2 bindings, publisher confirms, mandatory routing, DLQ handling, recovery, and monotonic aggregate stream versions remain stricter Nexzan safeguards on top of the Shope flow.

Outbox/Inbox make database effects reliable. They cannot make SSH, cloud-provider APIs, notifications, or other external calls transactional with MySQL. Those effects must use an event-ID-keyed durable operation record before they can claim end-to-end effectively-once execution.
