# Tenant Strict Mode

**Status:** Configurable; default is legacy/migration mode (`false`).
**Last updated:** 2026-06-23 (BACKLOG-TENANCY-023)

---

## Overview

Tenant strict mode hardens the X-Tenant-ID authority layer from a migration-friendly
passthrough to a production-ready enforcement posture. It is controlled by a single
environment variable and can be toggled without a code deployment.

```env
XDR_TENANT_STRICT_MODE=false   # legacy (default)
XDR_TENANT_STRICT_MODE=true    # production posture
```

**⚠ Legacy mode is not production-safe.** It exists only to maintain backward
compatibility during the migration period described in `TENANT_NULL_MIGRATION_PLAN.md`.

---

## Behaviour Comparison

| Condition | Legacy mode (`false`) | Strict mode (`true`) |
|---|---|---|
| No header on index/listing routes (member-user) | null (no scoping) | **403 TenantContextMissingException** |
| No header on object-scoped routes (`show`, `review`) | null (no scoping) | **403 TenantContextMissingException** |
| No header on store routes (member-user) | null → null tenant_id | **403 TenantContextMissingException** |
| **Admin, no header on read routes** | Accepted — unscoped | Accepted — unscoped (bypass preserved) |
| **Admin, no header on store routes** | null → null tenant_id | **403 TenantContextMissingException** |
| **Admin, `X-Tenant-ID: _global` on store** | null → null tenant_id | null → null tenant_id (explicit) |
| Admin, valid tenant header | Accepted (any tenant) | Accepted (any tenant) |
| User with **zero memberships**, any header | Accepted (pass-through) | **403 TenantSpoofAttemptException** |
| User with memberships, **valid** tenant header | Accepted | Accepted |
| User with memberships, **foreign** tenant header | 403 TenantSpoofAttemptException | 403 TenantSpoofAttemptException |
| Any user, `X-Tenant-ID: _global` | 403 TenantSpoofAttemptException | 403 TenantSpoofAttemptException |

---

## Who Bypasses Strict Mode

Strict mode gates apply only to regular authenticated users. Two principals are
exempt:

1. **Global platform admin** (`role = admin`): bypasses membership checks in
   both modes for **read routes**. On **store routes** in strict mode, admins
   must explicitly declare scope (BACKLOG-023):
   - `X-Tenant-ID: tenant-A` → record created scoped to `tenant-A`
   - `X-Tenant-ID: _global` → record created with `tenant_id = NULL` (explicit global)
   - No header → **403** `TenantContextMissingException` (accidental null prevented)
   
   In legacy mode, no header on store routes still creates a `null` tenant_id (backward compat).

2. **System/Artisan context** (`TenantContextAuthority::resolveSystemContext()`):
   returns a typed context record without touching `validateAndResolve()`. Artisan
   commands, Go services, and Python services use this path. Strict mode is
   irrelevant — there is no User object, so no membership check is performed.

---

## Which Routes Require Tenant Context

All tenant-scoped routes use `requireTenantContext: true` in `validateAndResolve()`.
In legacy mode this parameter has no effect — missing headers are always allowed
through regardless of the value.

| Route | requireTenantContext | Added by |
|---|---|---|
| `GET /advisory/findings` (index) | `true` | BACKLOG-022 |
| `GET /advisory/findings/{id}` | `true` | BACKLOG-021 |
| `POST /advisory/findings/{id}/review` | `true` | BACKLOG-021 |
| `GET /dlq/records` (index) | `true` | BACKLOG-022 |
| `GET /dlq/records/{id}` | `true` | BACKLOG-021 |
| `POST /dlq/records/{id}/review` | `true` | BACKLOG-021 |
| `GET /shadow-soak` (index) | `true` | BACKLOG-022 |
| `POST /shadow-soak` (store) | `true` | BACKLOG-022 |
| `GET /shadow-soak/{runId}` | `true` | BACKLOG-021 |

## Creation Safety (BACKLOG-022 / 023)

In strict mode, user-facing store actions must not create `tenant_id = NULL`
records unless the caller explicitly signals global-scope intent (BACKLOG-023).

| Actor | Header supplied | Outcome |
|---|---|---|
| Member user, no header | — | 403 `TenantContextMissingException` |
| Member user, valid header | `X-Tenant-ID: tenant-A` | Record created with `tenant_id = 'tenant-A'` |
| Admin, no header | — | **403** `TenantContextMissingException` (BACKLOG-023) |
| Admin, `X-Tenant-ID: _global` | — | Record created with `tenant_id = NULL` (explicit) |
| Admin, valid tenant header | `X-Tenant-ID: tenant-A` | Record created with `tenant_id = 'tenant-A'` |
| Zero-membership user, no header | — | 403 `TenantContextMissingException` |
| Zero-membership user, any header | `X-Tenant-ID: tenant-A` | 403 `TenantSpoofAttemptException` |
| Non-admin, `X-Tenant-ID: _global` | — | 403 `TenantSpoofAttemptException` |

### `_global` scope sentinel

`TenantContextAuthority::GLOBAL_SCOPE = '_global'` is a reserved `X-Tenant-ID`
value for admin-only explicit global-scope creation. It signals deliberate intent
to create a record without a tenant boundary. Only `role = admin` users may use it.

**Legacy mode behavior (non-production):** store routes create `tenant_id = NULL`
when no header is supplied, regardless of the user's membership or role. This is
preserved for migration compatibility only and must not be used in production.

### Null-record audit command

```sh
php artisan tenant:null-audit                         # report all isolated tables
php artisan tenant:null-audit --table=advisory_findings
php artisan tenant:null-audit --output=reports/tenant_null_audit.json
```

Read-only. Never mutates records. Exit code 0 = all clean; 1 = nulls found.
See Phase 3 of `docs/security/TENANT_NULL_MIGRATION_PLAN.md` for backfill guidance.

---

## Exception Types

All three exceptions map to **HTTP 403 Forbidden** via the global exception handler.

| Exception | When thrown |
|---|---|
| `TenantContextMissingException` | Strict mode + `requireTenantContext=true` + no header, or strict mode + `requireExplicitScope=true` (admin, store) + no header |
| `TenantSpoofAttemptException` | User claims a tenant they are not a member of, or non-admin uses `_global` sentinel |
| `TenantBoundaryViolationException` | Validated tenant_id mismatches the record's tenant_id |

These are distinct so that monitoring and error logs can differentiate:
- **Missing** context: user forgot the header
- **Spoof**: user claimed a tenant they don't belong to
- **Boundary violation**: user is in the right tenant context but accessed a wrong-tenant record

---

## Prerequisites Before Enabling Strict Mode

Complete the checklist in `TENANT_NULL_MIGRATION_PLAN.md`. Key prerequisites:

- [ ] Phase 3 backfill complete — no null `tenant_id` records in tenant-scoped tables
- [ ] All regular users provisioned in `user_tenant_memberships`
- [ ] All clients updated to send `X-Tenant-ID` on object-scoped requests
- [ ] Artisan commands that touch tenant-scoped tables use `resolveSystemContext()`,
      not `validateAndResolve()` (they run without an HTTP request, so no header exists)
- [ ] Go and Python services updated to pass `X-Tenant-ID` on inbound HTTP calls
      to the SOC control-plane API
- [ ] Full test suite passes with `XDR_TENANT_STRICT_MODE=true` in CI

---

## Enabling in Production

1. Confirm all prerequisites above are met.
2. Set `XDR_TENANT_STRICT_MODE=true` in the production `.env`.
3. Reload config: `php artisan config:cache`.
4. Monitor `TenantContextMissingException` and `TenantSpoofAttemptException` rates
   in the application logs for the first 24h — unexpected 403s indicate a client
   that has not been updated to send the header.

---

## References

- `app/Services/TenantContextAuthority.php` — `validateAndResolve()`, `isStrictMode()`
- `app/Exceptions/TenantContextMissingException.php`
- `app/Exceptions/TenantSpoofAttemptException.php`
- `docs/security/TENANT_ISOLATION_POSTURE.md`
- `docs/security/TENANT_NULL_MIGRATION_PLAN.md`
- `tests/Feature/TenantStrictModeTest.php`
