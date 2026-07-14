# API Versioning & Deprecation Policy

## Scope

Applies to `routes/api.php` — the stateless, Sanctum-token-authenticated
HTTP API surface (`/api/v1/*`, and the unprefixed `/api/*` alias below).
It does **not** apply to the session-authenticated `/api/*` routes defined
in `routes/web.php` (the SOC console's own frontend API, RBAC-gated,
CSRF-protected, never called by external clients).

## Current state (2026-07-14)

Every route is registered under two paths, resolving to the identical
controller/closure — there is exactly one implementation, never two
copies that could drift:

- **`/api/v1/...`** — the canonical, versioned path. New integrations
  (endpoint-agent builds, external tooling) should target this.
- **`/api/...`** (unprefixed) — kept for backward compatibility with
  already-deployed endpoint agents and any other existing client that
  predates versioning. Functionally identical to `/v1` today.

## Deprecation policy

1. **A breaking change always ships as a new version** (`/v2`), never as
   an in-place change to `/v1`. "Breaking" means: removing a field,
   changing a field's type/meaning, removing an endpoint, or changing
   required auth/request shape.
2. **Non-breaking changes** (new optional fields, new endpoints) may be
   added to the current version directly.
3. **The unprefixed alias is deprecated, not removed, today.** It stays
   functionally identical to `/v1` for as long as any endpoint agent in
   the field might still call it. Actually retiring it requires:
   - Confirming (via access logs / `SecurityRequestLogger`) that no
     production traffic has hit the unprefixed paths for a full
     deployment cycle, and
   - A recorded go/no-go decision, not a silent removal.
4. **Sunset timeline for any version** (once `/v2`+ exists): a minimum of
   one full release cycle of dual-serving both versions before the older
   one is removed, with the removal announced in `docs/RELEASE_NOTES.md`.
5. **Endpoint agents specifically** (`services/endpoint-agent`,
   `scripts/build_agent_package.py`) should be updated to call `/v1`
   explicitly in the same change that introduces `/v2`, so the fleet
   migrates off the implicit "whatever `/api/...` currently means"
   behavior before it can become ambiguous.
