# Production Runtime Profile & Safety Gates

Defines the expected runtime security posture for each deployment profile.
Enforced by: `python scripts/xdr_posture_check.py --profile=<profile>`

---

## Profiles

| Profile | Purpose | Posture |
|---|---|---|
| `local` | Local development and demo/thesis walkthroughs | WARN for gaps; never FAIL |
| `staging` | Pre-production validation; must mirror production posture | FAIL for secrets/auth gaps |
| `production` | Production pilot | FAIL for any critical misconfiguration |

---

## Running the Checker

```powershell
# Local env check
python scripts/xdr_posture_check.py --profile=local

# Staging env check
python scripts/xdr_posture_check.py --profile=staging --env-file=.env.staging

# Production env check with JSON report
python scripts/xdr_posture_check.py --profile=production --env-file=.env.production \
  --output=reports/posture_check.json
```

Exit codes: `0` = PASS, `1` = FAIL (at least one FAIL-level issue), `2` = ERROR (env file unreadable).

---

## Security Gates by Profile

| Check | ID | local | staging | production |
|---|---|---|---|---|
| `APP_DEBUG=false` | C-01 | WARN | WARN | **FAIL** |
| `APP_FORCE_HTTPS=true` | C-02 | WARN | WARN | **FAIL** |
| `SESSION_SECURE_COOKIE=true` | C-03 | WARN | WARN | **FAIL** |
| `XDR_TENANT_STRICT_MODE=true` | C-04 | WARN | **FAIL** | **FAIL** |
| `XDR_ENFORCE_INTERNAL_AUTH=true` | C-05 | WARN | **FAIL** | **FAIL** |
| `XDR_INGEST_SECRET` not placeholder | C-06 | WARN | **FAIL** | **FAIL** |
| `XDR_INTERNAL_AUTH_SECRET` not placeholder | C-07 | WARN | **FAIL** | **FAIL** |
| Per-service tokens not placeholder | C-08 | WARN | **FAIL** | **FAIL** |
| `XDR_SHADOW_CONSUMER_ENABLED=false` | C-09 | INFO | WARN | **FAIL** |
| DLQ consumer flags off | C-10 | INFO | INFO | WARN |

---

## Required Keys for Production

All of the following must be set to non-placeholder values before deploying.
Generate secrets with: `openssl rand -hex 32`

```env
# Security posture
APP_DEBUG=false
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
XDR_TENANT_STRICT_MODE=true
XDR_ENFORCE_INTERNAL_AUTH=true

# Secrets (generate independently)
XDR_INGEST_SECRET=<64-char hex>
XDR_INTERNAL_AUTH_SECRET=<64-char hex>
XDR_NORMALIZER_INTERNAL_TOKEN=<64-char hex>
XDR_ALERT_WRITER_INTERNAL_TOKEN=<64-char hex>
XDR_INCIDENT_BUILDER_INTERNAL_TOKEN=<64-char hex>
XDR_CORRELATION_INTERNAL_TOKEN=<64-char hex>

# Consumer posture
XDR_SHADOW_CONSUMER_ENABLED=false
XDR_DLQ_CONSUMER_ENABLED=false
XDR_CORRELATION_DLQ_CONSUMER_ENABLED=false
XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED=false

# Consumer offset policy
XDR_ALERT_WRITER_AUTO_OFFSET_RESET=latest
XDR_INCIDENT_BUILDER_AUTO_OFFSET_RESET=latest
```

---

## Deferred Production Risks

These are surfaced as WARN in all profiles. They are valid enterprise reliability
concerns that must be addressed before high-concurrency or multi-tenant production
deployment. See `REVIEW_REJECTED.md §2` for full detail and `BACKLOG-INGESTION-025`
for implementation tracking.

| ID | Risk | Production Gate |
|---|---|---|
| D-01 / IG-1 | Synchronous metrics polling in ingestion-gateway request path | Before production load test |
| D-02 / IG-2 | Global rate limiter — token starvation under multi-tenant load | Before multi-tenant pilot onboarding |
| D-03 / IG-3 | 15-second retry timeout — socket exhaustion under Redpanda outage | Before high-concurrency production deployment |
| D-04 / INFRA-3 | No container CPU/memory limits in docker-compose | Before any multi-tenant production pilot |

---

## Accepted Risks

These are surfaced as INFO in all profiles. They are intentional posture decisions
for the local/demo operational context. See `REVIEW_REJECTED.md §3` for full detail.

| ID | Risk | Condition to Re-Evaluate |
|---|---|---|
| A-01 / DB-3 | Seeder users have no tenant memberships | Enable `XDR_TENANT_STRICT_MODE=true` in production |
| A-02 / DB-4 | Demo alerts/incidents have `tenant_id=NULL` | Enable `XDR_TENANT_STRICT_MODE=true` in production |
| A-03 / INFRA-4 | Grafana provisioning mounts are writable | Use `:ro` mounts in production deployment |
| A-04 / RAG-1 | Empty knowledge base on fresh deploy | Add RAG seeding runbook to production operator onboarding |

---

## Integration with CI / Pre-Deploy Gate

Add to any CI pipeline or pre-deploy checklist:

```powershell
python scripts/xdr_posture_check.py --profile=production --env-file=.env.production \
  --output=reports/posture_check.json
if ($LASTEXITCODE -ne 0) { exit 1 }
```

The checker is read-only and has no side effects on the running platform.

---

## Related Documents

- `docs/security/TENANT_STRICT_MODE.md` — tenant strict mode details
- `docs/security/TENANT_ISOLATION_POSTURE.md` — full tenant isolation posture
- `docs/operations/OPERATIONAL_POSTURE.md` — current correlation mode and domain status
- `REVIEW_REJECTED.md` — deferred and accepted risk classification
