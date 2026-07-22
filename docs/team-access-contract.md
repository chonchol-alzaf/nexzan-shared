# Team Access Contract

This document defines the shared team access model used across Nexzan services. Gateway computes request-time access and sends it through `X-Internal-Token`; downstream services consume the signed token through `Auth::canTeam()` or `check.team.access:*`.

## Ownership

| Concept | Owner | Responsibility |
| --- | --- | --- |
| `account_status` | Gateway/admin | Account lifecycle, admin/security/legal restrictions. |
| `billing_status` | Billing | Billing/trial/payment lifecycle. |
| grace policy | Billing | Temporary billing exception for restricted billing states. |
| `effective_access` | Gateway | Final request-time capability snapshot sent to downstream services. |

Local team tables in Atom/Site/Billing/Gateway are read models. Request authorization should use the token capability snapshot, not local status queries.

## Account Status

Account status has highest priority. If account status blocks access, billing status and grace do not override it.

| Status | Meaning | Can view resources? | Can use resources? | Can create/upgrade? | Billing/payment access |
| --- | --- | --- | --- | --- | --- |
| `active` | Normal account. Billing and grace rules apply. | Depends on billing/grace | Depends on billing/grace | Depends on billing/grace | Yes |
| `suspended` | Admin/security/legal suspension. Resource access blocked. | No | No | No | Yes, so the team can resolve billing when relevant |
| `terminated` | Account permanently closed. | No | No | No | No |

Account status values come from `AccountStatusEnum`.

## Billing Status

Billing status applies only when `account_status=active`.

| Status | Meaning | Effective access |
| --- | --- | --- |
| `trialing` | Team is in trial/signup bonus period. | Full resource access: view, use, create/upgrade. |
| `current` | Billing is healthy/current. | Full resource access: view, use, create/upgrade. |
| `hold` | Billing needs payment, but existing resources may continue. | Can view and use existing resources; cannot create, resize, or add paid services. |
| `suspended` | Billing is restricted after payment failure or unresolved billing issue. Running servers should be powered off by Atom when this transition is consumed. | Can view resources only; cannot use existing resources or create/upgrade. |

Billing status values come from `BillingStatusEnum`. 
## Grace Period Policy

Grace is a temporary override owned by Billing. It applies only when:

- `account_status=active`
- `billing_status` is `hold` or `suspended`
- grace start/end window is active

Grace is ignored for `trialing` and `current` because those statuses already have full access.

| Policy | Meaning | Effective access |
| --- | --- | --- |
| `full_access` | Temporarily restore full access. | View, use, and create/upgrade resources. |
| `existing_resources_only` | Existing resources can continue, but new cost is blocked. | View and use existing resources; cannot create, resize, or add paid services. |
| `view_only` | Team can inspect resources but cannot operate them. | View resources only; cannot use or create/upgrade. |

Grace policy values come from `GracePeriodPolicyEnum`.

## Effective Access Capabilities

Gateway sends only these team capabilities:

| Capability | Meaning | Typical routes |
| --- | --- | --- |
| `view_dashboard` | Dashboard/UI visibility. | Dashboard pages. |
| `view_resources` | Resource list/detail visibility. | Server/site/volume list and show routes. |
| `use_resource` | Existing resource operation. | Power, deploy, run command, rollback deploy. |
| `create_resource` | New resource or cost/capacity change. | Create server/site/database/daemon, resize, add paid volume/service. |

Delete/removal actions should not require `use_resource` or `create_resource` when they reduce or remove billable resources. For example, server delete, volume delete, backup disable, detach, and cleanup-style routes should remain available during `hold`, `billing_status=suspended`, and `view_only` states unless the account is terminated or administratively suspended.

Removed capabilities must not be reintroduced: `deploy`, `power_on_resource`, `resize_resource`, `add_paid_service`, `view_billing`, `make_payment`, `operate_resource`.

## Final Access Matrix

Assuming grace is not active:

| Account status | Billing status | `view_dashboard` | `view_resources` | `use_resource` | `create_resource` |
| --- | --- | --- | --- | --- | --- |
| `active` | `trialing` | true | true | true | true |
| `active` | `current` | true | true | true | true |
| `active` | `hold` | true | true | true | false |
| `active` | `suspended` | true | true | false | false |
| `suspended` | any | false | false | false | false |
| `terminated` | any | false | false | false | false |

With active grace on an active account:

| Billing status | Grace policy | `view_dashboard` | `view_resources` | `use_resource` | `create_resource` |
| --- | --- | --- | --- | --- | --- |
| `hold` or `suspended` | `full_access` | true | true | true | true |
| `hold` or `suspended` | `existing_resources_only` | true | true | true | false |
| `hold` or `suspended` | `view_only` | true | true | false | false |

## Authorization Rules

- Gateway blocks resource forwarding only when `view_resources=false`.
- Downstream services must check `use_resource` for existing-resource operations.
- Downstream services must check `create_resource` for new resources, resize/capacity changes, and paid add-ons.
- Downstream services should not block normal delete/removal actions with `use_resource` or `create_resource`.
- Billing/payment route access is controlled by Gateway account lifecycle rules, not `effective_access`.
- `Auth::canTeam()` fails closed for missing team, missing access payload, unknown capability, or false value.

## Billing Suspension Power-Off

When Billing transitions a team to `billing_status=suspended`, it publishes `team.billing_status.updated`. Atom owns server/provider operations and should consume that event to power off running servers for the team.

Rules:

- Do not power off servers for `billing_status=hold`.
- Power off running servers when `billing_status=suspended` is consumed.
- Do not auto power on servers when billing returns to `current`; users can power on manually after access is restored.
- Gateway and Billing must not call provider power APIs directly for this flow.

## Billing Suspension Resource Deletion

If a team remains in `billing_status=suspended` longer than the configured retention period, resources should be deleted. The default retention period is 14 days unless service configuration sets a different value.

Rules:

- Billing owns the suspended-age check and retention configuration.
- Billing should publish `team.billing_suspension.cleanup_requested` only after `billing_status=suspended` has exceeded the configured retention period.
- Atom owns deletion of infrastructure resources such as servers and volumes.
- Site owns deletion of site-layer resources such as sites, databases, database users, cron jobs, daemons, caches, commands, logs, file-manager records, deploy history, SSL, vhosts, and related site/server records.
- Gateway must not delete resources for this flow.
- Payment recovery before the retention period expires should prevent deletion and move billing back to `current`.
- Cleanup handlers must be idempotent because deletion requests/events may be delivered more than once.
