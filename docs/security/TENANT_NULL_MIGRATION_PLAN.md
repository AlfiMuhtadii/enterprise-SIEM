# Tenant Null Migration Plan

**Status:** Phase 3 canonical tenant decided and backfill procedure executed against
the dev DB (0 null rows — nothing to migrate there). No destructive migration has
been run against any environment; the dev DB's zero-row result is not itself proof
the procedure is safe against real data — re-run and re-verify before staging/production.
**Last updated:** 2026-07-16 (Phase 3 execution — see `docs/security/RLS_DECISION_RECORD.md`
for the full rationale and current gate status)

---

## Problem Statement

Several tables have a nullable `tenant_id` column. Records created before
multi-tenancy was introduced carry `tenant_id = NULL`. These "unscoped" records
are currently accessible from any tenant context (including no-context requests),
which is the intended backward-compatible behaviour for single-tenant deployments.

Before these records can be isolated to a specific tenant (required for true
multi-tenant production use), each null-tenant record must be assigned a
canonical `tenant_id`. This document describes the safe migration path.

---

## Affected Tables

| Table | Nullable tenant_id? | Introduced | Records at risk |
|---|---|---|---|
| `security_alerts` | Yes (added BACKLOG-019) | 2026-06-23 | All pre-019 alerts |
| `security_incidents` | Yes (added BACKLOG-019) | 2026-06-23 | All pre-019 incidents |
| `advisory_findings` | Yes (from creation) | 2026-06-23 | All pre-020 findings |
| `dlq_records` | Yes (from creation) | 2026-06-23 | All pre-020 DLQ records |
| `shadow_soak_runs` | Yes (from creation) | 2026-06-24 | All pre-020 soak runs |

---

## Current Behaviour (Phase 0 — Status Quo)

```
TenantBoundaryService::assertAccess(null, 'tenant-A') → ALLOW
TenantBoundaryService::assertAccess('tenant-A', null) → ALLOW
```

Null records are "global" — visible to all tenant contexts. This is safe for
single-tenant deployments and protects existing data from being silently hidden.

---

## Migration Phases

### Phase 1 — Schema Foundation ✓ (BACKLOG-019, 2026-06-23)
- Added `tenant_id` (nullable, indexed) to `security_alerts` and `security_incidents`.
- Application-layer enforcement via `TenantBoundaryService`.
- RLS not enabled.

### Phase 2 — Authority Validation ✓ (BACKLOG-020, 2026-06-23)
- Added `user_tenant_memberships` table and `TenantContextAuthority` service.
- X-Tenant-ID is now validated against user memberships (selector, not authority).
- Null-tenant records remain accessible (backward compat).

### Phase 3 — Default Tenant Assignment (2026-07-16 — canonical tenant decided, procedure executed against dev DB)
**Goal:** Assign every null-tenant record a `tenant_id` so no record is unscoped.

**Steps:**
1. ✓ Canonical default tenant identifier decided: **`system`**. Chosen so
   legacy/system audit-style records stay admin/system-visible without ever
   being mixed into a real client tenant's data.
2. ✓ The non-destructive Artisan command already existed (`ENTERPRISE-046`):
   ```
   php artisan tenant:backfill-preflight              # read-only audit, always safe
   php artisan tenant:backfill-nulls --tenant=system --dry-run
   php artisan tenant:backfill-nulls --tenant=system
   ```
3. The command issues `UPDATE table SET tenant_id = ? WHERE tenant_id IS NULL`
   for each of `TenantBoundaryService::MUTABLE_TABLES` (13 tables, batched).
   This is a mutable UPDATE (allowed on non-append-only tables like
   `security_alerts` and `security_incidents`).
4. Append-only tables (`TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES`)
   are never touched by the command — enforced in code, not just by convention.
5. ✓ Ran against the real dev DB 2026-07-16: preflight showed 0 null rows
   across all 13 mutable tables (this DB has no accumulated pre-BACKLOG-019
   data), then ran `tenant:backfill-nulls --tenant=system` for real anyway to
   exercise the full procedure — 0 rows updated, all tables `CLEAN`.
   **This is not proof the procedure is safe against real data** — it only
   proves the tooling runs cleanly end-to-end. Re-run against staging/
   production, where real null rows are expected to exist.
6. Full suite: `php artisan test --parallel --recreate-databases` (unaffected —
   backfill is a DB-only operation against `detector`, not `detector_test`;
   this step doesn't touch application code).

**Prerequisite for staging/production runs:** At least one tenant has been
provisioned in `user_tenant_memberships` and tested end-to-end in that
environment. **Not required for admin-role visibility** —
`TenantContextAuthority::isAdminBypass()` already lets `admin`-role users see
any tenant's data including `system`, without a membership row.

**Estimated downtime:** None — UPDATE on nullable column does not require a lock
beyond the row-level lock held for each UPDATE batch. Use batched UPDATEs
(`LIMIT 1000`, the command's default) on large tables to avoid lock escalation.

### Phase 4 — NOT NULL Constraint (PLANNED, after Phase 3)
**Goal:** Enforce that all new records have a tenant_id.

**Steps:**
1. Verify Phase 3 backfill is 100% complete (zero null rows in all tables).
2. Add a database-level NOT NULL constraint:
   ```sql
   ALTER TABLE security_alerts ALTER COLUMN tenant_id SET NOT NULL;
   ALTER TABLE security_incidents ALTER COLUMN tenant_id SET NOT NULL;
   ```
3. Add `->nullable(false)` to the Eloquent model `$fillable` casts
   and application-layer validation where records are inserted.
4. Update `TenantBoundaryService::assertAccess()` to treat null record
   tenant_id as a schema violation (remove the null-pass-through).

**Risk:** Any code path that inserts without a `tenant_id` will begin failing
at the DB level. Audit all insert points before applying.

### Phase 5 — PostgreSQL Row-Level Security (PLANNED, after Phase 4)
**Goal:** Move enforcement from application layer to the database engine.

**Steps:**
1. Create a per-table RLS policy:
   ```sql
   ALTER TABLE security_alerts ENABLE ROW LEVEL SECURITY;
   CREATE POLICY tenant_isolation ON security_alerts
       USING (tenant_id = current_setting('app.tenant_id', true));
   ```
2. Add a Laravel middleware that sets `SET app.tenant_id = ?` at the start of
   each DB connection (using the validated tenant_id from TenantContextAuthority).
3. Add a bypass role for migrations and Artisan commands:
   ```sql
   CREATE ROLE xdr_admin BYPASSRLS;
   ```
4. Run the full cross-tenant penetration test matrix from
   `TenantIsolationHardeningTest` and `TenantContextAuthorityTest` against RLS.
5. Update `TenantBoundaryService::RLS_ENABLED = true`.

**Prerequisite:** Full test coverage of multi-tenant scenarios including replay
and Artisan commands that run without a user session.

---

## Rollback Path

Each phase is independently reversible:
- Phase 3 rollback: Reset tenant_id to NULL for the backfilled rows (inverse UPDATE).
- Phase 4 rollback: Drop the NOT NULL constraint (`ALTER COLUMN SET DEFAULT NULL`).
- Phase 5 rollback: `ALTER TABLE ... DISABLE ROW LEVEL SECURITY`.

The application-layer enforcement (`TenantBoundaryService` + `TenantContextAuthority`)
remains active at all phases and does not depend on RLS being enabled.

---

## Prerequisites for Multi-Tenant Production

Before enabling tenant isolation in production:
- [ ] Phase 3 backfill command written, tested in staging, and validated
- [ ] All null-tenant records backfilled
- [ ] `user_tenant_memberships` seeded for all users
- [ ] All Artisan commands that touch tenant-scoped tables updated to pass
      system context (not user context)
- [ ] Go and Python services updated to include `X-Tenant-ID` or equivalent
      internal tenant headers on API calls
- [ ] RLS tested under concurrent replay load
- [ ] Cross-tenant penetration test matrix extended to cover RLS-bypass scenarios

---

## References

- `app/Services/TenantBoundaryService.php` — object-level enforcement
- `app/Services/TenantContextAuthority.php` — header authority validation
- `docs/security/TENANT_ISOLATION_POSTURE.md` — current posture and table register
- `tests/Feature/TenantIsolationHardeningTest.php` — cross-tenant denial matrix
- `tests/Feature/TenantContextAuthorityTest.php` — membership and spoof tests
