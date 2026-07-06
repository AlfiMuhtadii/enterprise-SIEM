# Production Deployment Profile

**Task:** ENTERPRISE-036  
**Status:** Controlled production-pilot posture. Not a full HA or commercial release claim.

---

## Scope and Posture

This document describes the controlled production-pilot deployment posture for the XDR
platform. It supplements the base `docker-compose.yml` with a hardening overlay
(`docker-compose.prod.yml`) and defines the required environment variable posture.

**This is NOT:**
- A claim of full enterprise HA or geo-redundancy
- A commercial product release
- A hyperscale SIEM deployment

**This IS:**
- A controlled production-pilot posture for academic thesis demonstration
- A hardened local/single-host deployment with reduced attack surface
- A documented set of operational boundaries and accepted risks

---

## Architecture Boundaries

Active domains (correlation engine, alert write, incident build):
- identity, cloud, SaaS — **staged_active** (6h soak PASS 2026-05-14)

Shadow / advisory domains (no active alert path):
- endpoint behavioral analytics — advisory-only
- DNS / proxy / firewall — shadow analytics, no blocking
- threat-intel / IOC — shadow-only

No domain promotion is permitted without a domain-specific 6h soak PASS.

---

## Deployment

### Base + Production Overlay

```bash
# Default services (datastores + observability)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# + pipeline services (Go + Python)
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile=strangler up -d

# + Laravel SOC application
docker compose -f docker-compose.yml -f docker-compose.prod.yml --profile=strangler --profile=app up -d
```

### Validate Profile

```bash
python scripts/xdr_production_profile_validate.py --profile=production
```

Expected: `OVERALL=PASS` with no FAIL-level findings.

---

## Environment Configuration

Copy `.env.production.example` to `.env` and replace all `REPLACE_WITH_*` values before
deploying.

### Required flags

| Variable | Required Value | Purpose |
|---|---|---|
| `XDR_TENANT_STRICT_MODE` | `true` | Enforce tenant context on all SOC routes |
| `XDR_ENFORCE_INTERNAL_AUTH` | `true` | Validate per-service internal tokens |
| `APP_DEBUG` | `false` | Prevent debug output and stack traces |
| `APP_FORCE_HTTPS` | `true` | Redirect all HTTP to HTTPS |
| `SESSION_SECURE_COOKIE` | `true` | Session cookies require TLS |
| `APP_ENV` | `production` | Disable dev/debug Laravel behaviors |

### Required internal service tokens

Generate each token with: `openssl rand -hex 32`

| Variable | Notes |
|---|---|
| `XDR_INTERNAL_AUTH_SECRET` | Shared validation key (ingestion-gateway) |
| `XDR_NORMALIZER_INTERNAL_TOKEN` | normalizer-worker service token |
| `XDR_ALERT_WRITER_INTERNAL_TOKEN` | alert-writer-service service token |
| `XDR_INCIDENT_BUILDER_INTERNAL_TOKEN` | incident-builder-service service token |
| `XDR_CORRELATION_INTERNAL_TOKEN` | correlation-worker service token |

### Forbidden placeholder values

Token fields must not contain any of: `admin`, `password`, `detector`,
`DetectorAdmin123!`, `changeme`, `change-me`, `example`, `secret`, `local`,
`dev-secret-change-me`, or any `REPLACE_WITH_*` value.

---

## Port Security Posture

The production overlay (`docker-compose.prod.yml`) removes all public datastore port
bindings and restricts administrative interfaces to localhost:

| Service | Dev port | Production posture |
|---|---|---|
| PostgreSQL | `5432` public | **removed** — docker exec or bastion only |
| ClickHouse | `8123`, `9000` public | **removed** |
| OpenSearch | `9200`, `9600` public | **removed**; security plugin also enabled (auth + demo-cert TLS) — see PDP-16 below |
| Qdrant | `6333`, `6334` public | **removed** |
| Grafana | `3000` public | `127.0.0.1:3000` — SSH tunnel |
| Redpanda Pandaproxy | `8082` public | `127.0.0.1:8082` — SSH tunnel |
| Redpanda Console | `8080` public | `127.0.0.1:8080` — SSH tunnel |
| Redpanda Kafka (19092) | public | **removed** |
| Laravel SOC app | `8000` public | public (required for browser + agents) |
| Ingestion-gateway | `8090` public | public (required for endpoint agents) |

Access Grafana remotely: `ssh -L 3000:localhost:3000 user@host`

---

## Grafana Provisioning

Provisioning and dashboard directories are mounted read-only in the production overlay to
prevent configuration drift:

```yaml
grafana:
  volumes:
    - ./infra/analytics/grafana/provisioning:/etc/grafana/provisioning:ro
    - ./infra/analytics/grafana/dashboards:/var/lib/grafana/dashboards:ro
```

---

## Restart Policies

All critical services have `restart: always` in the production overlay:

| Service category | Restart policy |
|---|---|
| redpanda, postgres, app | `always` |
| ingestion-gateway, normalizer-worker | `always` |
| correlation-worker, alert-writer-service | `always` |
| incident-builder-service, queue, scheduler | `always` |

---

## Shadow / DLQ Consumer Posture

In production, shadow and DLQ consumers are disabled by default to prevent advisory
findings from unreviewed sources appearing in the SOC dashboard:

```dotenv
XDR_SHADOW_CONSUMER_ENABLED=false
XDR_DLQ_CONSUMER_ENABLED=false
XDR_CORRELATION_DLQ_CONSUMER_ENABLED=false
XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED=false
```

To enable a consumer, set the flag and restart the relevant service. DLQ replay runs
exclusively via `php artisan dlq:replay` — never from HTTP handlers.

---

## Backup and Recovery

### PostgreSQL

```bash
# Full dump
pg_dump -U xdr_user -d xdr_db > backups/xdr_$(date +%Y%m%d_%H%M%S).sql

# Restore
psql -U xdr_user -d xdr_db < backups/xdr_YYYYMMDD_HHMMSS.sql
```

### Log and report paths

| Artifact | Path |
|---|---|
| Security detector log | `storage/logs/security.jsonl` |
| Validation reports | `reports/` |
| Soak reports | `reports/xdr_correlation_soak_6h.json` |
| Pilot evidence freeze | `docs/validation/LIVE_035_EVIDENCE_FREEZE.md` |

Backup `storage/logs/security.jsonl` and `reports/` before each deployment.

### Recovery validation

```bash
python scripts/xdr_recovery_validate.py --output reports/recovery_validation.json
```

Full runbook: `docs/operations/BACKUP_RESTORE_RECOVERY.md`

---

## Healthchecks

Healthchecks are defined in the base `docker-compose.yml` for critical services.
The production overlay does not redefine them — they are inherited.

Verify with: `docker compose ps` — all critical services should show `healthy`.

---

## Observability

Runtime posture:

```bash
python scripts/xdr_posture_check.py --profile=production
```

SLO readiness:

```bash
python scripts/xdr_observability_slo_validate.py --profile=production
```

Grafana dashboards available at `http://localhost:3000` (via SSH tunnel in production).

---

## Security Constraints

- `InternalServiceAuthMiddleware` enforced on all `/api/internal/*` routes when
  `XDR_ENFORCE_INTERNAL_AUTH=true`
- Endpoint agent response framework allows only non-destructive commands:
  `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`
- EASM is advisory-only — no active scanning, no firewall rule changes
- No autonomous incident creation from shadow findings or DLQ records
- All response plans require explicit analyst approval (`mark_completed` forbidden without
  analyst action)
- Append-only tables cannot be mutated (enforced by model `save()` override)

---

## Accepted Risks

| Risk | Condition for re-evaluation |
|---|---|
| Single-host deployment (no HA) | Before commercial deployment |
| Redpanda single-node | Before pilot with SLA commitments |
| PostgreSQL without streaming replication | Before production data retention requirements |
| Healthchecks inherited from base compose | Before automated rolling restart deployment |
| Shadow consumers off-by-default | Before analyst review workflow enablement |

See `REVIEW_REJECTED.md` Section 3 for full accepted risk register.

## Deferred Items

| Item | Gate for re-evaluation |
|---|---|
| TLS termination at ingestion-gateway | Before external agent deployment |
| Kubernetes migration | Before commercial deployment |
| Multi-tenant PostgreSQL RLS enforcement | Before multi-tenant pilot |
| Production HA / multi-node Redpanda | Before SLA commitments |

See `REVIEW_REJECTED.md` Section 2 for full deferred backlog.

---

## Validator Reference

```bash
python scripts/xdr_production_profile_validate.py [OPTIONS]

Options:
  --profile    local | staging | production (default: local)
  --env-file   env file to validate (default: .env.production.example)
  --compose-file  compose overlay to validate (default: docker-compose.prod.yml)
  --output     write JSON report to path
  --quiet      suppress console output
```

Checks PDP-01 through PDP-16. Exit 0=PASS, 1=FAIL, 2=ERROR.

| Check | Description | Severity (production) |
|---|---|---|
| PDP-01 | docker-compose.prod.yml exists | FAIL |
| PDP-02 | .env.production.example exists | FAIL |
| PDP-03 | XDR_TENANT_STRICT_MODE=true | FAIL |
| PDP-04 | XDR_ENFORCE_INTERNAL_AUTH=true | FAIL |
| PDP-05 | All required internal tokens present | FAIL |
| PDP-06 | No placeholder/default secrets in tokens | FAIL |
| PDP-07 | Datastores not publicly exposed | FAIL |
| PDP-08 | Pandaproxy not publicly exposed | FAIL |
| PDP-09 | Grafana provisioning mounts are read-only | FAIL |
| PDP-10 | Critical services have restart: always | FAIL |
| PDP-11 | Healthchecks present for critical services | WARN |
| PDP-12 | Backup/report/log paths documented | WARN |
| PDP-13 | Accepted and deferred risks referenced | WARN |
| PDP-14 | No active scanning / autonomous remediation | FAIL |
| PDP-15 | Datastore credentials fail closed (no weak defaults) | FAIL |
| PDP-16 | OpenSearch security plugin enabled in production | FAIL |

PDP-15 (ENT-SEC-WEAK-DEFAULT-SECRETS): the base `docker-compose.yml` falls
back to well-known weak defaults for datastore credentials
(`${DB_PASSWORD:-postgres}`, `${CLICKHOUSE_PASSWORD:-detector}`,
`${GF_SECURITY_ADMIN_PASSWORD:-admin}`, `${OPENSEARCH_INITIAL_ADMIN_PASSWORD:-DetectorAdmin123!}`).
`docker-compose.prod.yml` overrides `postgres`, `clickhouse`, `grafana`, and
`opensearch` with `${VAR:?message}` fail-closed syntax so a production
deploy refuses to start rather than silently accepting the dev defaults if
the operator forgets to set them. The check also verifies the production
env file doesn't leave these on a placeholder value.

PDP-16 (ENT-SEC-OPENSEARCH-OPEN): the base `docker-compose.yml` runs
OpenSearch with `plugins.security.disabled: "true"` for local/demo
convenience — unauthenticated, plain HTTP. `docker-compose.prod.yml`
overrides this to `"false"`, which also switches OpenSearch to its bundled
self-signed demo certificate for the HTTP layer (i.e. `https://` with an
unverified cert). `alert-writer-service` and Laravel (`SiemSearchService`,
`XdrStorageValidateCommand`) authenticate via `XDR_OPENSEARCH_USER`/
`XDR_OPENSEARCH_PASSWORD` (must match the OpenSearch `admin` user and
`OPENSEARCH_INITIAL_ADMIN_PASSWORD`) and skip cert verification via
`XDR_OPENSEARCH_VERIFY_TLS=false` against that demo cert.

**Known interim gap:** the demo certificate is self-signed and unverified —
this closes the "unauthenticated" half of ENT-SEC-OPENSEARCH-OPEN (RBAC via
the built-in `admin` user) and encrypts the wire, but does not yet give a
properly trusted/rotatable certificate chain. Real PKI/mTLS for this and
every other internal hop (Pandaproxy, Postgres) is tracked separately by
ENT-SEC-NO-TLS-INTERNAL. This change was validated statically (`docker
compose config` against both the base file alone and the base+prod overlay,
with all required vars set and unset) — the Docker daemon was not running
in the environment this was implemented in, so a live container boot has
not been verified; run
`docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d opensearch alert-writer-service`
to confirm before relying on this in a real deployment.
