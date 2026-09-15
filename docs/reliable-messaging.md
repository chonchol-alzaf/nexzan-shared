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

When an aggregate type and ID are supplied, the shared package allocates a monotonic version under a database row lock in the same transaction. Consumers discard older or equal versions only for the replaceable snapshot events in `rabbitmq.snapshot_events`. The consumer version key includes producer, snapshot group (event type by default), aggregate type and ID. `rabbitmq.snapshot_event_groups` groups `site.ready` with `site.update`, and `project.created` with `project.updated`, because each pair writes the same details. They reuse the existing `site.update` and `project.updated` keys, so already consumed update versions remain effective without a schema migration. Team account, billing and grace snapshots keep separate keys; one cannot suppress another. Commands and membership deltas always process independently. This is not a guarantee of strict ordering across event types.

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

1. Review the coordinated shared and service PRs. Service Composer locks pin the shared feature-branch commit so review builds install the actual implementation. After approval, choose a release tag and update the constraints before production rollout. Do not deploy an old `v1.0.3` lock with the new service code.
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
- one `php artisan operations:work --sleep=3` (required for Atom, Billing and Site Inbox side effects)
- one `php artisan consume:<domain>` process for every consumed domain

Both recovery commands are registered every minute with `withoutOverlapping()->onOneServer()`:

```shell
php artisan outbox:recover
php artisan inbox:recover --batch=100
```

Handlers throw `MessageDependencyNotReady` when a valid event arrives before its required team, user, or billable server purchase. The entire handler transaction rolls back and the Inbox becomes `waiting`, with a default 30-second delay (`RABBITMQ_INBOX_DEPENDENCY_BACKOFF`). Waiting does not consume the normal failure-attempt budget. `inbox:recover` dispatches due waiting rows; duplicate Rabbit deliveries respect the same delay. Missing dependencies are not marked completed or silently dropped. Monitor their unresolved age with `messaging:health`; a dependency that never arrives remains visible for investigation. Other exceptions continue through failed/backoff/dead handling.

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
- oldest waiting Inbox age and its dependency error
- counts by Outbox/Inbox status and retry attempts
- broker queue depth, unacked messages, DLQ depth, publisher returns/NACKs, and consumer count
- stale publishing/queued/processing leases
- Horizon failed jobs and process health

Do not prune unresolved rows. Retention requires a separate reviewed policy.

## Final-state guarantees and boundary

All audited Gateway, Atom, Billing, and Site RabbitMQ producers use the Outbox. Rabbit handlers receive only an Inbox primary ID, and `processed_events` is removed. Exact v2 bindings, publisher confirms, mandatory routing, DLQ handling, recovery, and monotonic aggregate stream versions remain stricter Nexzan safeguards on top of the Shope flow.

Inbox-triggered Atom power-off, team cleanup and SSH-key removal, Billing signup emails, and Site Git-key/hook cleanup now use `durable_operations`. The record is unique by event ID and operation key and commits with Inbox/domain changes. `operations:work` claims one operation, commits the claim, then executes its locally constructed job outside the database transaction. Serialized job payloads are encrypted with the service APP_KEY; retain previous keys during rotation, and keep job classes backward compatible while rows remain unresolved.

Completed operations are never automatically repeated. External effects cannot share a MySQL transaction: exceptions go to `needs_review`, and an interrupted worker leaves `processing`. Neither state is automatically retried, because a remote request may already have succeeded. Alert on both failed and old processing rows. Stop the relevant worker, inspect provider/resource state, then resolve explicitly:

```shell
php artisan operations:review OPERATION_ID
php artisan operations:review OPERATION_ID --retry --inspected
php artisan operations:review OPERATION_ID --complete --inspected
```

Pending operations are automatically picked up after worker restart. Replaying a failed parent Inbox cannot create a second operation with the same key. Existing jobs invoked outside an Inbox retain their normal Laravel dispatch behavior. This closes the Inbox-to-secondary-job loss window; it does not claim exactly-once external APIs, SSH or notifications.

## Verification and monitoring handoff

`php artisan messaging:health --max-age=300` emits JSON backlog counts and oldest unresolved age, returning nonzero for dead/review-required work or an old backlog. Run it from your existing monitoring agent on each service every minute and alert on nonzero exit. Monitor broker queue depth, unacked deliveries, DLQ depth and consumer count with RabbitMQ's existing exporter, and Horizon failed jobs separately. This command does not contact or configure an external alert destination.

The integration harness uses a disposable MySQL socket under `/tmp/nexzan-messaging-integration.*`, RabbitMQ at localhost:15682 and Redis at localhost:16389. It creates unique test databases/queues and runs two real Horizon workers concurrently. Never point it at production infrastructure.

```shell
php nexzan-shared/tests/integration/smoke.php /absolute/path/to/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
php nexzan-shared/tests/integration/smoke.php /absolute/path/to/atom-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
```

Verified on 2026-09-07 with MySQL 8.0.46, RabbitMQ 3.12.1, Redis and Laravel 12.64.0 / 13.22.0: package migrations, producer rollback, mandatory confirmed delivery, broker duplicate deduplication, concurrent Horizon database effects, durable operation execution, invalid-envelope DLQ, DB-outage requeue, and unroutable publisher backoff. Production bindings export, legacy backlog drain, migrations and process activation remain deployment steps and must be done only for an approved release.

## Messaging contract corrections (2026-09-10)

- Site publishes `resource.site.site_name` for both readiness and updates, including renames. Gateway stores `sites.site_name` and returns `site_name` in project detail responses. Gateway migration `2026_09_10_000001_rename_site_domain_to_site_name.php` preserves existing values while renaming the column; consumers of the GET response must use the new key.
- Team primary keys are `teams.id`; `team_id` remains the foreign key on related records. Creation handlers initialize missing teams without resetting later account, billing or grace snapshots. Status handlers wait for missing teams instead of completing an update that affected no rows.
- Versioned site readiness/update messages share a snapshot group in Gateway and Atom; project creation/update messages share another group in Atom. A late readiness/creation message cannot overwrite details from a newer update. The group version advances in the same transaction as the successful handler; failures do not prevent an earlier valid event or the later retry from applying. Separate producers remain independent.
- Billing schedules `billing:request-suspended-resource-cleanup` every ten minutes. A team becomes eligible after `DELETE_RESOURCES_AFTER_SUSPEND_DAYS` (default 14) of billing suspension, provided it has no active grace period. The request creates a durable Atom operation, executed by `operations:work`. Successful local server/volume removal records `server.deleted`/`volume.deleted` in the same transaction. Outbox failure rolls the local removal back; confirmed external effects still require the durable-operation review process after an interruption.
- All Atom server deletion paths include `provider`, `billing_started_at` and `deleted_at`. Billing completes custom-server deletion without a purchase. Managed deletion waits for a missing billable purchase; an already closed purchase or an explicitly never-billed server is a no-op. Volume deletion includes `resource.volume.deleted_at` for the billing cutoff.
- Billing volume deletion locks the matching active purchase by team, volume ID and item type, and closes it at `resource.volume.deleted_at` (legacy fallback: envelope `occurred_at`). It preserves existing inactive/removed purchases and their cutoffs, including trial finalization. A missing purchase enters dependency waiting without exhausting retries; `inbox:recover` redispatches it after creation. Older scaling history cannot hide a current active purchase. A missing timestamp is a payload failure rather than a guessed billing cutoff.
- Versioned billing uses `resource.billing_transition.effective_at`. Atom retains `resource.server.completed_at` for compatible non-billing consumers. Legacy scaling receipts without authoritative sequence facts enter durable history recovery; they never substitute message-consumption time for the historical cutoff.
- GitHub hook cleanup requires HTTP 204, or a 404 followed by a successful complete hook listing proving that the hook is absent. A 404 alone can hide insufficient permissions (see [GitHub troubleshooting](https://docs.github.com/en/rest/using-the-rest-api/troubleshooting-the-rest-api)). Other failures and missing source-control credentials leave the operation in `needs_review`; they do not claim remote success or automatically repeat a potentially successful deletion.

### Server and site deletion retention

Gateway, Atom and Site retain deleted server/site records in their original tables using `deleted_at`. Normal queries in Gateway and Site, and site queries in Atom, exclude these rows. Atom servers keep their existing `status = removed` / `deleted_at` lifecycle and `notDeleted` scope. v1.1.2 additionally retains projects in Gateway and Atom. Volumes and Billing purchase history are outside this resource-row purge policy.

Deletion handlers preserve the original deletion time from the event, falling back to envelope `occurred_at` for older site events. Repeated deletion messages do not reset the retention date. Server deletion also soft-deletes its sites. Domain changes and the completed Inbox receipt commit together; source deletion and its Outbox event retain their existing transaction boundary.

All three services schedule the shared command daily at midnight in the application's timezone:

```shell
php artisan resources:purge-deleted --dry-run
php artisan resources:purge-deleted --batch=500
```

The command permanently removes rows whose `deleted_at` is at least six calendar months old, in batches, with sites processed before servers. Rows become eligible at the six-month cutoff and are removed by the next successful daily run. Purging bypasses model observers: it does not repeat provider cleanup, publish another deletion, or change the billing cutoff. The six months concern database row retention; physical server cleanup and deletion-based billing closure happen at the original resource deletion, independently of this purge.

Before applying a resource message, the shared Inbox processor locks the resource lifecycle (parent server first for site writes) and checks for deletion. A late create/ready/update for a deleted ID completes without recreating or changing the resource. After row purge, the existing completed Inbox deletion receipt or source Outbox deletion event provides the same protection. **Keep these deletion events in the existing delivery ledgers, including after six months.** Removing both the resource row and its deletion receipts would remove this protection. No additional tombstone table is created; lifecycle mutexes use the existing `consumed_aggregate_versions` table. Deleted IDs are terminal and must not be reused for a new resource.

Apply these migrations before enabling the changed models and resource-message configuration:

- Gateway: `2026_09_10_000002_add_soft_deletes_to_resource_projections.php` (after the site-name rename).
- Atom: `2026_09_10_000001_add_site_soft_deletes_and_server_retention_index.php`.
- Site: `2026_09_10_000001_add_soft_deletes_to_servers_and_sites.php`.

These migrations add nullable columns/indexes and retain existing data. They do not run the purge. The scheduler and current shared package must be installed for automatic purging; cached configuration and long-running workers must be refreshed as part of deployment.

`tests/integration/resource-retention.php` verifies real MySQL concurrent child deletion versus late readiness, source server deletion versus child creation, and post-purge protection using the Outbox receipt. It uses two PHP processes and waits for observed InnoDB lock contention. Run it only against a disposable socket (no RabbitMQ or provider access):

```shell
php nexzan-shared/tests/integration/resource-retention.php /absolute/path/to/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
```

Service regression tests build isolated SQLite schemas. Bypass any local cached configuration with `APP_CONFIG_CACHE=/tmp/nexzan-no-cached-config.php` so the new resource maps are exercised. The Site unit fixtures use a connection named `mysql` configured as SQLite in memory; use `DB_CONNECTION=mysql` for that suite.

### Shared release v1.1.0

v1.1.0 contains dependency waiting/recovery, shared site/project snapshot groups, the resource-deletion guard and six-month purge command, and `Messaging\BillingTransition` for versioned billing facts/fingerprints and fractional interval hours. `server.scaled` is no longer a snapshot event: a later scale cannot suppress an earlier billable interval. Billing lifecycle sequence and Outbox aggregate version are independent. Source histories, consumer reconciliation and financial readiness checks live in the Atom/Billing services, not in this transport package.

All four services must pin and install the actual v1.1.0 tag through Composer; manually copying source into vendor is not a release. Stop consumers/Horizon/operation workers for a coordinated application update, apply the service migrations before activation, rebuild cached configuration and restart every long-running worker. The `waiting` status uses the existing string Inbox column and requires no extra shared schema migration. Keep the scheduler running for Inbox/history recovery and retention purging.

The full billing transition contract, source checkpoint endpoint, legacy-baseline policy and payment/bonus safeguards are documented in Billing's `docs/scaling-event-ordering.md`. The scaling timestamp for the new contract is `resource.billing_transition.effective_at`; the older server completion fields remain in publisher payloads for other consumers. Unsequenced scaling receipts request history recovery instead of guessing predecessor/price facts.


## v1.1.1: claims and independent server projections

Apply the additive `2026_09_15_000001_add_inbox_dispatch_token` migration in every consuming service before starting the new workers. The service job constructors accept an optional token; older serialized jobs retain a null default. Stop all consumers, queue workers and publishers during the coordinated package/application update, install the release, migrate, refresh configuration, declare bindings, start consumers/workers, and finally resume publishers.

Inbox dispatch now locks the receipt, assigns a unique `dispatch_token`, and commits `queued` before pushing the job. Enqueue is deferred until the outermost transaction commits. A queue-push exception may update only its own still-queued claim; a fast worker's `waiting`, `failed` or `completed` result is never reset. Jobs from replaced claims are ignored. A process crash between commit and enqueue leaves a stale claim recoverable by `inbox:recover`, which rechecks eligibility under the row lock. Dependency waiting does not consume ordinary failure attempts.

`FieldVersionProjection` reuses `consumed_aggregate_versions` under the resource lifecycle lock. Server created/ready/status events share the existing `server.status_update` field watermark; IP/OS readiness uses `server.readiness`. Completed Inbox evidence initializes missing watermarks using current locking reads. Each group advances only with a successful transaction. Preserve `InboxExecutionContext` around the handler so projection helpers use the stored envelope identity/version. Do not add `server.ready` or `server.scaled` to the whole-message snapshot list: their independently ordered fields and immutable billing facts must still be processed.

Gateway additionally binds `server.scaled`. Its current plan follows the dedicated billing transition sequence, independent of publication `aggregate_version`. A late B cannot replace C; a noop does not suppress a delayed successful plan. Missing server creation waits, malformed or conflicting facts fail visibly, and the existing deletion guard wins. Initial creation provides a fallback plan; unsequenced legacy ready messages only update readiness. Previously published scaled messages are not replayed by adding a binding; any historical projection repair is separate and requires source evidence.

Declare Gateway with `php artisan consume:server --declare-only` before restarting Outbox publishers. Operate Inbox/Outbox recovery every minute, Billing `billing:recover-history` every minute, and `operations:work` for durable side effects. Horizon and RabbitMQ consumers alone do not run these recovery commands. The Laravel scheduler also owns business schedules and six-month retention; a timer dedicated to messaging recovery does not replace those unrelated schedules.

Regression harness: `php tests/integration/inbox-claims.php /absolute/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock`. It creates/drops its own MySQL database, observes real InnoDB lock waits, and verifies claim fencing, outer-commit enqueue, stale recovery, fast dependency waiting and field-version bootstrap under REPEATABLE READ. It never connects to a service database.


## v1.1.3: safe durable-operation dependency retry

Apply `2026_09_15_000003_add_durable_operation_retry_time` in every service before starting v1.1.3 workers. `MessageDependencyNotReady` from a durable operation now returns it to pending with `next_attempt_at` (default 30 seconds, `RABBITMQ_OPERATIONS_DEPENDENCY_BACKOFF`). The worker skips pending operations until due. Other exceptions still go to `needs_review`; inspect uncertain external effects before retrying. Throw the dependency exception only when retry is safe, such as an unavailable authorization service before a provider call. Already completed resource steps must remain idempotent across a deferred batch.

Atom's suspension cleanup uses Billing request identities and per-resource execution claims. Billing cancellation and claims share a Team transaction lock; Site waits for confirmed `server.deleted`. Upgrade Billing schema/producer/authorization endpoint and Atom/Site handlers together. Old jobs without request IDs require review and cannot delete resources. Do not replay them with an invented identity; a fresh eligible Billing request is required.

## v1.1.2: publisher ownership and membership/project ordering

Apply shared migration `2026_09_15_000002_add_outbox_publish_token` in all four services and service migration `2026_09_15_000003_add_project_soft_deletes` in Atom/Gateway before restarting workers. Install the real v1.1.2 tag, refresh config and restart long-running processes together. Old publisher processes must be stopped: they cannot enforce the new ownership condition.

Outbox workers now claim one message immediately before publishing it, up to the requested batch limit. Each claim has a unique `publish_token`. Success/failure updates require the same token and `publishing` status; recovery invalidates the token. An old process can still have an uncertain broker outcome, but cannot reset a newer claim/result. Event-ID deduplication remains required. Retries of inspected dead events invalidate any previous token.

`FieldVersionProjection::applySnapshot` applies an explicitly sequenced nested snapshot under the resource lifecycle lock. Its sequence is independent of the enclosing event aggregate. Service handlers validate payload identity and sequence before calling it. `ResourceDeletionGuard::isDeleted` exposes the same lock/deletion check for nested resources; delivery-ledger checks use current locking reads even inside an existing MySQL transaction.

Atom orders membership add/remove under `team.membership`, keyed by `team_user` (team ID + user ID). A delayed add cannot undo removal; a newer invitation can restore membership. Existing completed add/remove receipts initialize the watermark. Removal's durable SSH cleanup is processed independently of whether its membership state is stale.

Atom handles project details with the existing `project.updated` field watermark instead of skipping the entire project message. Gateway includes `{team_id, project_id, version}` in `resource.default_project` on every project create/update, including name-only changes, and on initial user/team registration. The version is allocated from the `team_default_project` source stream while holding the Team lock in the same transaction as the carrying Outbox event. Atom applies it in the Team's `team.default_project` group, independent of project name/version. No new queue binding is required. Legacy embedded defaults cannot overwrite a sequenced snapshot. Ambiguous legacy changes without ordering evidence wait for a sequenced source snapshot; timestamps order older contracts when available.

Gateway and Atom projects use same-row soft deletion with six-calendar-month retention. New deletion payloads include `project_id`, the project snapshot, `deleted_at`, and the source Team's replacement default ID. Atom can retain the row when deletion arrives before creation. Older ID-only deletions are protected by the completed Inbox receipt even if there was no row. Creation, update and registration handlers respect deletion, including after purge. Preserve deletion delivery receipts indefinitely; deleted project IDs are terminal. Purge processes projects after sites and servers without republishing deletion.

Atom enforces each move's source-project precondition. A stale command completes with actual placements and `rejected_server_ids`; it does not apply a second move from an obsolete source. Missing live dependencies wait. Each completion includes `placements: [{server_id, project_id, version}]`, where version is allocated per server from the `server_placement` source stream. Placement, sequence allocation and completion Outbox commit together. Gateway applies these facts per server in `server.project`, independent of source-project aggregate versions, and waits for missing server creation. Deleted servers are skipped. Legacy completion messages are source-checked and cannot overwrite an already sequenced placement; pre-upgrade histories without per-server facts cannot establish the full new guarantee.

Maintained regression traits live in Atom/Gateway `tests/Concerns/*MessagingOrderingChecks.php`. `tests/integration/outbox-ownership.php` uses two PHP processes and a disposable MySQL socket to check old publish outcomes and a nested projection competing for an InnoDB lock. The broker responses in this harness are simulated; no real broker/provider or service database is accessed.
