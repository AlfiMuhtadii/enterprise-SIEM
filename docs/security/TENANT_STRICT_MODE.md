# Tenant Strict Mode

**Status:** Configurable; default is legacy/migration mode (`false`).
**Last updated:** 2026-06-23 (BACKLOG-TENANCY-021)

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
| No `X-Tenant-ID` header on listing routes | null (no scoping) | null (no scoping) |
| No header on object-scoped routes (`show`, `review`) | null (no scoping) | **403 TenantContextMissingException** |
| Admin user, any header or no header | Accepted (bypass) | Accepted (bypass) |
| User with **zero memberships**, any header | Accepted (pass-through) | **403 TenantSpoofAttemptException** |
| User with memberships, **valid** tenant header | Accepted | Accepted |
| User with memberships, **foreign** tenant header | 403 TenantSpoofAttemptException | 403 TenantSpoofAttemptException |

---

## Who Bypasses Strict Mode

Strict mode gates apply only to regular authenticated users. Two principals are
exempt:

1. **Global platform admin** (`role = admin`): bypasses all membership checks in
   both modes. Admins can supply any tenant header or no header at all. If no
   header is supplied, the request is unscoped (can see all records regardless
   of `tenant_id`).

2. **System/Artisan context** (`TenantContextAuthority::resolveSystemContext()`):
   returns a typed context record without touching `validateAndResolve()`. Artisan
   commands, Go services, and Python services use this path. Strict mode is
   irrelevant — there is no User object, so no membership check is performed.

---

## Which Routes Require Tenant Context

Routes that fetch or mutate a **specific record** opt in via
`requireTenantContext: true` in `validateAndResolve()`:

| Route | requireTenantContext |
|---|---|
| `GET /advisory/findings/{id}` | `true` |
| `POST /advisory/findings/{id}/review` | `true` |
| `GET /dlq/records/{id}` | `true` |
| `POST /dlq/records/{id}/review` | `true` |
| `GET /shadow-soak/{runId}` | `true` |
| `GET /advisory/findings` (index) | `false` |
| `GET /dlq/records` (index) | `false` |
| `GET /shadow-soak` (index) | `false` |
| `POST /shadow-soak` (store) | `false` |

In legacy mode, `requireTenantContext` has no effect — missing headers are
always allowed through.

---

## Exception Types

All three exceptions map to **HTTP 403 Forbidden** via the global exception handler.

| Exception | When thrown |
|---|---|
| `TenantContextMissingException` | Strict mode + `requireTenantContext=true` + no header |
| `TenantSpoofAttemptException` | User claims a tenant they are not a member of |
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
