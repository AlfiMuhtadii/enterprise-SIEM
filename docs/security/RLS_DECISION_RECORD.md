# RLS Decision Record — Tenant Isolation Architecture

**Task:** ENTERPRISE-040  
**Decision date:** 2026-06-25  
**Status:** DECIDED — app-layer isolation enforced; PostgreSQL RLS deferred to Phase 5  
**Decision owner:** Platform architecture

---

## Context

The XDR platform handles security alerts, DLQ records, advisory findings, and shadow-soak
runs that must be isolated between tenants in a future multi-tenant deployment. The question
is: **at what layer should tenant row isolation be enforced, and when?**

Three options were considered:

| Option | Description |
|---|---|
| A | App-layer only (current) |
| B | App-layer + PostgreSQL RLS in parallel |
| C | Full RLS with app-layer as defense-in-depth |

---

## Decision

**Option A — app-layer enforcement now; PostgreSQL RLS deferred** is the chosen posture for
the current production-pilot phase.

**Rationale:**
1. The platform is in academic-pilot/single-tenant posture. No live multi-tenant production
   traffic exists yet. The isolation gap carries no active risk in this phase.
2. RLS requires a phased prerequisite chain (see [Phase Roadmap](#phase-roadmap)):
   backfill null-tenant records (Phase 3), enforce NOT NULL (Phase 4), then activate RLS (Phase 5).
   Phase 3 is blocked until a canonical default tenant is agreed and staging validated.
3. Application-layer enforcement (`TenantBoundaryService` + `TenantContextAuthority`) is
   fully tested and provides the required isolation guarantee for the production-pilot gate.
4. RLS introduces `SET app.tenant_id` session injection — this couples every DB connection
   to the tenant context, including Artisan commands, Go pipeline services, and Python services
   that currently use system context. Auditing and updating all these callers requires more
   time than the pilot window allows.
5. The existing control: `TenantBoundaryService::RLS_ENABLED = false` is a machine-readable
   sentinel that monitoring and the posture validator (`xdr_tenant_isolation_posture.py`) can
   assert. No silent drift is possible.

**What this decision does NOT do:**
- It does not accept RLS as permanently deferred. Phase 5 is a required gate before commercial
  multi-tenant production (see [RLS Promotion Gates](#rls-promotion-gates)).
- It does not permit new tables to be created without `tenant_id` on any table that stores
  per-tenant operational data.
- It does not exempt the null-tenant backfill (Phase 3) from being completed before
  strict mode is enabled in production.

---

## Current Isolation Stack

```
HTTP Request
  │
  ▼
TenantContextAuthority::validateAndResolve()
  │  — validates X-Tenant-ID header against user_tenant_memberships
  │  — blocks spoof attempts (TenantSpoofAttemptException → 403)
  │  — in strict mode: blocks missing header on required routes (TenantContextMissingException → 403)
  │
  ▼
Controller / Service
  │  — calls TenantBoundaryService::assertAccess(record.tenant_id, request_tenant_id)
  │  — calls TenantBoundaryService::scopeQuery(query, tenant_id) on list routes
  │  — TenantBoundaryViolationException → 403 if mismatch
  │
  ▼
PostgreSQL (no RLS)
  │  — row is read/written based on app-layer WHERE clause only
```

**Database-level RLS:** NOT active. `TenantBoundaryService::RLS_ENABLED = false`.

---

## Table Classification

### App-Layer-Isolated (RLS candidate — primary SOC data)

Tables with `tenant_id` where active tenant enforcement matters most.
These are the first candidates for RLS Phase 5.

| Table | Nullable tenant_id | App enforcement | Notes |
|---|---|---|---|
| `security_alerts` | Yes (legacy) | `scopeQuery` in list | Added BACKLOG-019; null = legacy single-tenant |
| `security_incidents` | Yes (legacy) | `scopeQuery` in list | Added BACKLOG-019; null = legacy single-tenant |
| `advisory_findings` | Yes | `scopeQuery` + `assertAccess` show/review | Shadow analytics; advisory-only |
| `dlq_records` | Yes | `scopeQuery` + `assertAccess` show/review | Mutable DLQ state |
| `shadow_soak_runs` | Yes | `scopeQuery` + `assertAccess` show | Soak harness mutable run state |
| `user_tenant_memberships` | n/a (IS the authority table) | Authority source | Scoped by user_id |

### Append-Only Event / Audit (RLS candidate — lower priority)

Immutable event log tables. Cannot be updated or deleted.
RLS adds defense-in-depth but is not the primary risk surface since
these tables are written by system paths, not directly queryable by users.

| Table | Nullable tenant_id | Append-only | Notes |
|---|---|---|---|
| `advisory_finding_events` | Yes | Yes | Analyst review audit trail |
| `dlq_normalization_events` | Yes | Yes | DLQ review audit trail |
| `shadow_soak_evidence_snapshots` | Yes | Yes | Soak evidence |
| `shadow_soak_gate_checks` | Yes | Yes | Soak gate results |
| `shadow_soak_domain_assessments` | Yes | Yes | Domain scoring |
| `shadow_soak_finding_summaries` | Yes | Yes | Soak findings |
| `shadow_soak_confidence_bands` | Yes | Yes | Confidence metrics |
| `shadow_soak_suppression_stats` | Yes | Yes | Suppression stats |
| `shadow_soak_coverage_stats` | Yes | Yes | Coverage stats |
| `shadow_soak_audit_events` | Yes | Yes | Soak audit trail |
| `tenant_membership_audit_events` | n/a (indexed by tenant_id) | Yes | Membership changes |

### Documented Isolation Gap (UNISOLATED — missing tenant_id)

These tables **lack** a `tenant_id` column entirely. They must receive a `tenant_id`
column and backfill before multi-tenant production is safe.

| Table | Gap reason | Risk in current posture | Required action |
|---|---|---|---|
| `security_audit_trails` | Column not yet added | Low — single-tenant pilot | Phase 3: add tenant_id |
| `telemetry_events` | Column not yet added | Low — single-tenant pilot | Phase 3: add tenant_id |
| `endpoint_agents` | Column not yet added | Low — single-tenant pilot | Phase 3: add tenant_id |
| `endpoint_agent_heartbeats` | Column not yet added | Low — single-tenant pilot | Phase 3: add tenant_id |
| `users` | User model is global | Low — auth is per-user not per-tenant | Phase 3: evaluate scope |

### Global / System (no tenant scope required)

| Table | Rationale |
|---|---|
| `migrations` | Framework table; always global |
| `failed_jobs` / `jobs` | Queue infrastructure; global |
| `personal_access_tokens` | Per-user, not per-tenant |
| `password_reset_tokens` | Auth infrastructure; global |
| `notification_delivery_logs` | Infrastructure logging; global |

### Legacy-Null-Tolerated

All tables in the **App-Layer-Isolated** and **Append-Only Event** categories above
carry nullable `tenant_id`. Records inserted before BACKLOG-019 (2026-06-23) have
`tenant_id = NULL` and are accessible from all tenant contexts. This is the intentional
backward-compatible behaviour for single-tenant deployments.

`null` pass-through logic in `TenantBoundaryService::assertAccess()`:
```php
if ($recordTenantId === null || $requestTenantId === null) {
    return; // ALLOW
}
```

This pass-through will be removed in Phase 4 after all null records are backfilled.

---

## Phase Roadmap

### Phase 1 — Schema Foundation ✓ (BACKLOG-019, 2026-06-23)
- `tenant_id` (nullable, indexed) added to `security_alerts` and `security_incidents`
- Application-layer enforcement via `TenantBoundaryService`

### Phase 2 — Authority Validation ✓ (BACKLOG-020/021/022/023, 2026-06-23)
- `user_tenant_memberships` table and `TenantContextAuthority` service
- X-Tenant-ID validated against memberships
- `XDR_TENANT_STRICT_MODE` flag (default: false = legacy passthrough)
- `requireTenantContext=true` on all object-scoped routes
- `requireExplicitScope=true` on all store routes (admin must declare intent)
- `_global` sentinel for explicit null-tenant creation (admin-only)

### Phase 3 — Default Tenant Assignment (PLANNED — blocks Phase 4 and 5)
- Decide canonical default tenant identifier
- Write `php artisan tenant:backfill-nulls --tenant=<id> [--dry-run]`
- Backfill all null-tenant records in mutable tables (no append-only updates)
- Add `tenant_id` to gap tables: `security_audit_trails`, `telemetry_events`,
  `endpoint_agents`, `endpoint_agent_heartbeats`
- Provision all regular users in `user_tenant_memberships`
- Enable `XDR_TENANT_STRICT_MODE=true` in staging; run full test suite

### Phase 4 — NOT NULL Constraint (PLANNED — requires Phase 3 complete)
- `ALTER TABLE ... ALTER COLUMN tenant_id SET NOT NULL` on primary data tables
- Remove null pass-through in `TenantBoundaryService::assertAccess()`
- Validate: zero null-tenant records after constraint

### Phase 5 — PostgreSQL Row-Level Security (PLANNED — requires Phase 4 complete)
- Per-table RLS policy:
  ```sql
  ALTER TABLE security_alerts ENABLE ROW LEVEL SECURITY;
  CREATE POLICY tenant_isolation ON security_alerts
      USING (tenant_id = current_setting('app.tenant_id', true));
  ```
- Laravel middleware to inject `SET app.tenant_id = ?` at DB session start
- Bypass role for migrations and Artisan: `CREATE ROLE xdr_admin BYPASSRLS;`
- Update Go pipeline services and Python services to include tenant context on
  internal SOC API calls
- Update `TenantBoundaryService::RLS_ENABLED = true`
- Full cross-tenant penetration test matrix under RLS

---

## RLS Promotion Gates

RLS (Phase 5) cannot be activated until ALL of the following are satisfied:

- [ ] Phase 3 backfill complete — zero null `tenant_id` in all isolated tables
- [ ] Phase 4 NOT NULL constraint active on `security_alerts`, `security_incidents`
- [ ] All regular users provisioned in `user_tenant_memberships`
- [ ] `XDR_TENANT_STRICT_MODE=true` passes full test suite in CI
- [ ] `tenant_id` column added to gap tables (see Documented Isolation Gap above)
- [ ] Artisan commands and Go/Python services updated for system context on DB write paths
- [ ] Laravel DB session middleware written and tested for `SET app.tenant_id`
- [ ] Bypass role (`xdr_admin BYPASSRLS`) created and Artisan uses it
- [ ] Cross-tenant penetration test matrix extended to cover RLS-bypass scenarios
- [ ] Domain-specific soak PASS for any domain promoted from shadow to active after RLS

**Validator:** `python scripts/xdr_tenant_isolation_posture.py --profile=production`  
Exits 1 if any FAIL-severity check fails.

---

## Accepted Risk Register

| Risk | Condition | Re-evaluation trigger |
|---|---|---|
| No DB-level RLS | Single-tenant pilot only; no live multi-tenant traffic | Any production multi-tenant onboarding |
| Null tenant_id records accessible cross-tenant | Backward compat for pre-019 records | Phase 3 backfill completed |
| `users` lacks `tenant_id` | Global user model; per-user auth sufficient for pilot | Multi-tenant user isolation required |
| `security_audit_trails` lacks `tenant_id` | Audit trails are global for pilot | Per-tenant audit export required |

---

## References

| Resource | Location |
|---|---|
| App enforcement service | `app/Services/TenantBoundaryService.php` |
| Authority validation service | `app/Services/TenantContextAuthority.php` |
| Null audit command | `app/Console/Commands/TenantNullAuditCommand.php` |
| Isolation posture doc | `docs/security/TENANT_ISOLATION_POSTURE.md` |
| Strict mode doc | `docs/security/TENANT_STRICT_MODE.md` |
| Null backfill plan | `docs/security/TENANT_NULL_MIGRATION_PLAN.md` |
| Posture validator | `scripts/xdr_tenant_isolation_posture.py` |
| App-layer tests | `tests/Feature/TenantIsolationHardeningTest.php` |
| App-layer tests | `tests/Feature/TenantContextAuthorityTest.php` |
| App-layer tests | `tests/Feature/TenantStrictModeTest.php` |
| App-layer tests | `tests/Feature/TenantIndexCreationSafetyTest.php` |
| App-layer tests | `tests/Feature/TenantNullCreationGuardTest.php` |
| RBAC coverage tests | `tests/Feature/RbacAuditCoverageTest.php` |
| Pilot readiness matrix | `app/Services/EnterprisePilotReadinessMatrixService.php` |
