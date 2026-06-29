# Tenant Isolation Posture

**Last updated:** 2026-06-29
**Status:** Application-layer enforcement only; PostgreSQL RLS scaffolded (advisory, not enforced).

---

## Current Posture

This platform enforces tenant isolation at the **application layer** via `TenantBoundaryService`.

PostgreSQL Row-Level Security (RLS) is **NOT** enabled. The migration
`2026_06_24_0500001_add_tenant_id_to_alerts_incidents.php` added `tenant_id`
columns to `security_alerts` and `security_incidents` but did not activate RLS
policies. All isolation enforcement flows through `TenantBoundaryService::assertAccess()`
and `TenantBoundaryService::scopeQuery()`.

## Tenant Context Source

The requesting tenant context is derived from the `X-Tenant-ID` HTTP request header.
The `users` table does NOT carry a `tenant_id` field. Tenant context is advisory
and header-driven.

### Backward Compatibility

- A record with `tenant_id = null` is treated as a **legacy / single-tenant** record
  and is accessible regardless of the request's tenant context.
- A request with no `X-Tenant-ID` header (null context) bypasses tenant filtering
  for backward compatibility. This is intentional for single-tenant deployments.

---

## Tables with Tenant Isolation

The following tables have a `tenant_id` column:

| Table | Has `tenant_id` | Append-Only |
|---|---|---|
| `advisory_findings` | Yes | No (mutable status) |
| `advisory_finding_events` | Yes | Yes |
| `dlq_records` | Yes | No (mutable status) |
| `dlq_normalization_events` | Yes | Yes |
| `shadow_soak_runs` | Yes | No (mutable status) |
| `shadow_soak_evidence_snapshots` | Yes | Yes |
| `shadow_soak_gate_checks` | Yes | Yes |
| `shadow_soak_domain_assessments` | Yes | Yes |
| `shadow_soak_finding_summaries` | Yes | Yes |
| `shadow_soak_confidence_bands` | Yes | Yes |
| `shadow_soak_suppression_stats` | Yes | Yes |
| `shadow_soak_coverage_stats` | Yes | Yes |
| `shadow_soak_audit_events` | Yes | Yes |
| `security_alerts` | Yes (added BACKLOG-019) | No |
| `security_incidents` | Yes (added BACKLOG-019) | No |
| `user_tenant_memberships` | Yes (BACKLOG-020) | Yes |
| `tenant_membership_audit_events` | Yes (BACKLOG-020) | Yes |
| `endpoint_agents` | Yes (added AGENT-TENANCY-GAP) | No (mutable) |
| `investigations` | Yes (added TENANT-UNSCOPED-TABLES) | No (mutable) |
| `response_plans` | Yes (added TENANT-UNSCOPED-TABLES) | No (mutable) |
| `entities` | Yes (added TENANT-UNSCOPED-TABLES) | No (mutable) |
| `threat_hunts` | Yes (added TENANT-UNSCOPED-TABLES) | Yes |
| `tenant_notification_settings` | Yes (added NOTIFY-TENANCY-GAP) | No (mutable upsert) |

> `notification_delivery_logs` also carries a nullable `tenant_id` (NOTIFY-TENANCY-GAP)
> for audit scoping, but is a write-only log table — not a tenant-owned resource — so it
> is not registered in `ISOLATED_TABLES`.

---

## Known Isolation Gaps

The following tables are **missing** `tenant_id` and are currently unscoped:

| Table | Gap Reason |
|---|---|
| `users` | User model is single-tenant; no tenant_id in current schema |
| `security_audit_trails` | Not yet updated |
| `telemetry_events` | Not yet updated |
| `endpoint_agent_heartbeats` | High-volume append telemetry; scoped via parent `endpoint_agents` |

These are documented isolation gaps. They do not represent active cross-tenant
leakage risk in current single-tenant deployments, but must be addressed before
multi-tenant production use.

---

## Notification Tenancy (NOTIFY-TENANCY-GAP)

SOC outbound notifications (webhook / Slack / Discord) are routed per tenant, so a
tenant's incident details are never delivered to another tenant's channel or to a
shared global channel by default.

- **Per-tenant targets:** `tenant_notification_settings` (mutable, isolated) holds one
  row per `tenant_id` with `webhook_url`, `slack_url`, `discord_url`, and an `enabled` flag.
- **Resolver:** `TenantNotificationResolver::resolve(?tenantId)` returns the effective
  targets for an incident:
  - `tenantId = null` → global config targets (`config/notifications_soc.php`) — legacy/demo path.
  - configured + enabled tenant → that tenant's URLs; a null channel inherits the global
    URL for that channel only (single-channel opt-in supported).
  - tenant row with `enabled = false` → **all channels suppressed** (explicit opt-out;
    the global channels are *not* used).
  - no tenant row → global config targets (backward compatible).
- **Audit scoping:** every delivery attempt is logged to `notification_delivery_logs`
  with the incident's `tenant_id`.
- **Callers:** `soc:sla-escalate` (`SocSlaEscalationCommand`) and `soc:notify-critical`
  (`SocNotifyCriticalCommand`) both resolve per-incident tenant targets before dispatch.

Posture: advisory dispatch only — no autonomous response. Notifications remain
simulated-by-default and do not mutate incidents or create alerts.

---

## Object-Level Authorization

Three controllers enforce tenant boundary via `TenantBoundaryService::assertAccess()`:

| Controller | Methods Protected |
|---|---|
| `AdvisoryFindingsController` | `show()`, `review()` |
| `DlqController` | `show()`, `review()` |
| `ShadowSoakController` | `show()` |

If the record belongs to tenant A and the request supplies `X-Tenant-ID: B`,
a `TenantBoundaryViolationException` is thrown, which the global exception handler
maps to HTTP 403 Forbidden.

---

## Roadmap to Full RLS

1. Enable `user.tenant_id` column and session-level `SET app.tenant_id` injection
2. Create PostgreSQL RLS policies per table:
   ```sql
   ALTER TABLE security_alerts ENABLE ROW LEVEL SECURITY;
   CREATE POLICY tenant_isolation ON security_alerts
       USING (tenant_id = current_setting('app.tenant_id', true)
              OR tenant_id IS NULL);
   ```
3. Add middleware to inject tenant context into each DB session from the
   authenticated user's `tenant_id`
4. Audit all raw `DB::table()` calls for RLS bypass (they respect RLS if the
   session variable is set)
5. Remove application-layer `where('tenant_id', ...)` scoping once RLS is active
   (defense-in-depth: keep both during transition)
6. Run cross-tenant penetration test matrix from `TenantIsolationHardeningTest`

---

## References

- `app/Services/TenantBoundaryService.php` — enforcement service
- `app/Exceptions/TenantBoundaryViolationException.php` — exception type
- `tests/Feature/TenantIsolationHardeningTest.php` — cross-tenant denial matrix
- `database/migrations/2026_06_24_0500001_add_tenant_id_to_alerts_incidents.php`
