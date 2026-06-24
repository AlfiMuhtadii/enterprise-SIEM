# EASM Passive Posture Monitoring

## Overview

External Attack Surface Management (EASM) provides passive visibility into the public-facing attack surface of registered web assets. All findings are advisory-only — they never create `security_incidents`, modify detection rules, or trigger autonomous responses.

**Posture:** Advisory-only, passive scanning only. No exploit scanning, no brute-forcing, no active probing.

---

## Architecture

```
WebsiteAsset (mutable)
    |
    +-- EasmScanRun (append-only per scan)
    +-- EasmFinding (mutable, DELETE forbidden, upserted per scan)

EasmPassiveScanService (PHP) -- orchestrates scan + persistence
xdr_easm_passive_scan.py (Python) -- performs passive network checks
EasmScanCommand (Artisan) -- CLI entry point
EasmController (HTTP) -- SOC UI + API
```

---

## Scan Policy

Only `passive` is implemented and enforced:

| Policy           | Status       | Checks                                                |
|------------------|--------------|-------------------------------------------------------|
| `passive`        | IMPLEMENTED  | dns, tls, http, security_headers, cookies, robots_txt, sitemap_xml |
| `safe`           | STUB ONLY    | Not implemented — passive + additional non-exploiting checks |
| `active_approved`| STUB ONLY    | Forbidden without domain-specific 6h soak PASS        |

---

## Passive Checks

| Check              | What it does                                      | Notes                     |
|--------------------|---------------------------------------------------|---------------------------|
| `dns`              | Resolves hostname; flags NXDOMAIN, private IPs    | Pure DNS resolution       |
| `tls`              | Checks cert expiry (14/30/90 day thresholds)      | TLS handshake only        |
| `http`             | GET request; checks reachability, redirects       | Max redirects = 5         |
| `security_headers` | Checks HSTS, CSP, X-Frame-Options, etc.           | Pure logic, no request    |
| `cookies`          | Checks Secure, HttpOnly, SameSite flags           | Pure logic, no request    |
| `robots_txt`       | Fetches /robots.txt; flags admin path hints       | Max 65,536 bytes          |
| `sitemap_xml`      | Checks /sitemap.xml existence and size            | Max 65,536 bytes          |

---

## Finding Severities

| Severity  | Examples                                         |
|-----------|--------------------------------------------------|
| `high`    | TLS not available, cert expiring within 14 days  |
| `medium`  | Missing HSTS, missing CSP, cookie missing Secure |
| `low`     | Missing X-Frame-Options, excessive redirects     |
| `info`    | Missing Permissions-Policy, oversized robots.txt |

All findings have `is_advisory = true`. Findings never create `security_incidents`.

---

## Private IP Safety

All private/internal IP ranges are permanently rejected before scanning:

- `127.x.x.x` (loopback)
- `10.x.x.x` (RFC-1918)
- `192.168.x.x` (RFC-1918)
- `172.16.x.x` -- `172.31.x.x` (RFC-1918)
- `.local`, `.internal`, `.lan`, `.corp`, `.test`, `.example`, `.invalid` TLDs
- `localhost`, `localhost.localdomain`

This protection cannot be disabled regardless of `--profile`.

---

## Database Tables

| Table             | Type            | Purpose                                |
|-------------------|-----------------|----------------------------------------|
| `website_assets`  | Mutable         | Registered domains/URLs per tenant     |
| `easm_scan_runs`  | Append-only     | Audit trail of each scan invocation    |
| `easm_findings`   | Mutable, no-delete | Current posture findings per asset   |

`easm_scan_runs` uses the standard append-only pattern: `save()` throws `LogicException` on update.

`easm_findings` uses `updateOrCreate` (upsert by `[tenant_id, asset_id, finding_key]`). DELETE is permanently forbidden via `delete()` / `forceDelete()` override.

---

## Running a Scan

### Artisan command

```bash
# Scan a registered asset
php artisan easm:scan 42 --tenant=tenant-001

# Dry-run (validate only)
php artisan easm:scan 42 --tenant=tenant-001 --dry-run

# Ad-hoc URL (not persisted)
php artisan easm:scan --url=https://example.com --tenant=tenant-001 --output=/tmp/report.json

# Staging/production profile
php artisan easm:scan 42 --tenant=tenant-001 --profile=production
```

### Python scanner directly (for testing)

```bash
python scripts/xdr_easm_passive_scan.py \
    --url https://example.com \
    --tenant-id tenant-001 \
    --asset-id 42 \
    --output /tmp/easm_report.json
```

---

## Validation

```bash
php artisan migrate:fresh --force && php artisan test --filter=EasmPassivePostureTest
python -m unittest discover -s tests/xdr_topic_bootstrap -p "test_xdr_easm_passive_scan.py" -v
```

---

## RBAC Permissions

| Permission     | Used for                              |
|----------------|---------------------------------------|
| `soc:easm.view` | List assets, view findings, show asset |
| `soc:easm.scan` | Register new asset, trigger scan      |

---

## Forbidden Operations

- `exploitScan()`, `activeScan()`, `runNuclei()`, `runZap()` — do NOT implement
- `brutableDirectory()`, `sqlInject()`, `xssProbe()` — do NOT implement
- `containTarget()`, `autoRemediate()` — do NOT implement
- Promoting EASM findings to `security_alerts` without a domain-specific 6h soak PASS
- Scanning private/internal IP ranges — permanently blocked
- DELETE on `easm_findings` — permanently forbidden
- UPDATE on `easm_scan_runs` — permanently forbidden (append-only)

---

## Operational Notes

- Findings upsert by `[tenant_id, asset_id, finding_key]`; `first_seen_at` is never overwritten on update
- `is_advisory = true` is hardcoded and cannot be changed
- Cross-tenant access is always rejected at `validateOwnership()`
- The Python scanner injects all network calls via `_fn` parameters for testability
