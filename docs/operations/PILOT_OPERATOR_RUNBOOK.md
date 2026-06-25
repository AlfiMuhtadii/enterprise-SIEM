# Pilot Operator Runbook

**Task:** ENTERPRISE-041  
**Audience:** Platform operator running a controlled enterprise pilot  
**Posture:** Single-tenant pilot, academic thesis demonstration  
**Last updated:** 2026-06-25

---

## Safety Boundaries

These constraints apply at all times. Violations require explicit architecture approval.

| Boundary | Enforcement |
|---|---|
| No active EASM scanning by default | `--scan-type=passive` only; `active_approved` is a stub |
| No autonomous containment | All response actions are analyst-approved before execution |
| No ACTIVE_ALLOWLIST mutation | No rule may be added to `ACTIVE_ALLOWLIST` without domain-specific 6h soak PASS |
| No shadow-to-active domain promotion | `endpoint`, `DNS`, `proxy`, `firewall` remain shadow-only until gate is passed |
| No self-approval | Command creator and approver must be different users |
| No destructive restore | Restore drill overwrites only an isolated target DB; active DB is never touched |
| Correlation fallback preserved | `XDR_CORRELATION_FALLBACK_TO_LEGACY=true` must not be removed |
| Append-only tables are immutable | No UPDATE or DELETE on audit/event tables (see CLAUDE.md for full list) |

---

## Prerequisites

### Required services

| Component | Role | Start profile |
|---|---|---|
| PostgreSQL | Primary SOC state | Default |
| Redpanda | Event streaming backbone | Default |
| ClickHouse | Async analytics | Default |
| OpenSearch | Alert indexing | Default |
| Qdrant | AI/RAG vector store | Default |
| Grafana | Observability dashboards | Default |
| ingestion-gateway | Signed telemetry entry point | `--profile=strangler` |
| normalizer-worker | Raw → normalized events | `--profile=strangler` |
| correlation-worker | Rule evaluation, shadow analytics | `--profile=strangler` |
| alert-writer-service | Alert persistence + indexing | `--profile=strangler` |
| incident-builder-service | Alert → incident aggregation | `--profile=strangler` |
| Laravel SOC | Control plane UI + RBAC | `--profile=app` |

### Required environment variables

Copy `.env.production.example` → `.env` and replace all `REPLACE_WITH_*` values.  
Critical flags for pilot:

```env
XDR_CORRELATION_ENGINE=go
XDR_CORRELATION_SCOPE=identity-cloud
XDR_CORRELATION_FALLBACK_TO_LEGACY=true
XDR_CORRELATION_FALLBACK_FAILURE_THRESHOLD=3
XDR_TENANT_STRICT_MODE=true
XDR_ENFORCE_INTERNAL_AUTH=true
APP_DEBUG=false
APP_ENV=production
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
```

### Required tools

| Tool | Purpose |
|---|---|
| `docker` + `docker compose` | Container orchestration |
| `php` (≥8.2) | Laravel artisan commands |
| `python3` (≥3.10) | Validation scripts |
| `pg_dump`, `pg_restore`, `createdb`, `dropdb`, `psql` | Restore drill |
| `openssl` | Token generation |

---

## Command Index

| # | Command | Purpose | Expected result | Report path |
|---|---|---|---|---|
| 1 | `docker compose up -d` | Start datastores + observability | All containers healthy | — |
| 2 | `docker compose --profile=strangler up -d` | Start pipeline services | All pipeline containers healthy | — |
| 3 | `docker compose --profile=strangler --profile=app up -d` | Start full platform | All containers healthy | — |
| 4 | `docker compose down` | Stop all services | Clean shutdown | — |
| 5 | `docker compose config --quiet` | Validate compose config | Exit 0, no errors | — |
| 6 | `python scripts/xdr_topic_bootstrap.py` | Bootstrap Redpanda topics | `BOOTSTRAP_RESULT=PASS` | `reports/xdr_topic_bootstrap.json` |
| 7 | `python scripts/xdr_posture_check.py --profile=production` | Runtime posture check | `OVERALL=PASS` | `reports/xdr_posture_check.json` |
| 8 | `python scripts/xdr_tenant_isolation_posture.py --profile=production` | Tenant isolation posture | `OVERALL=PASS` | `reports/xdr_tenant_isolation_posture.json` |
| 9 | `python scripts/xdr_production_profile_validate.py --profile=production` | Production profile validation | `OVERALL=PASS` | `reports/xdr_production_profile_validation.json` |
| 10 | `python scripts/xdr_recovery_validate.py --profile=production` | Recovery readiness check | `OVERALL=PASS` | `reports/xdr_recovery_validation.json` |
| 11 | `python scripts/xdr_restore_drill.py` | Restore drill dry-run | Exit 0 | `reports/restore_drill_dryrun.json` |
| 12 | `python scripts/xdr_restore_drill.py --execute` | Restore drill execute | Exit 0, POST checks pass | `reports/restore_drill_YYYYMMDD.json` |
| 13 | `python scripts/xdr_live_soak_validate.py` | Live soak dry-run | Exit 0 | — |
| 14 | `python scripts/xdr_live_soak_validate.py --execute` | Live soak execute (≤1000 events) | `OVERALL=PASS` | `reports/live_soak_YYYYMMDD.json` |
| 15 | `python scripts/xdr_pilot_live_validate.py` | Pilot live evidence validation | All 8 stages PASS | `reports/xdr_pilot_live_validation.json` |
| 16 | `python scripts/demo_causal_verify.py` | Live causal proof | `LIVE_CAUSAL_PROOF=PASS` | `reports/demo_causal_verify.json` |
| 17 | `php artisan easm:scan` | EASM passive scan (SOC CLI) | Exit 0, no errors | DB: `easm_scan_runs` |
| 18 | `python scripts/xdr_easm_passive_scan.py` | EASM passive scan (Python) | `OVERALL=PASS` | `reports/xdr_easm_passive_scan.json` |
| 19 | `python scripts/xdr_easm_posture_history.py` | EASM posture history | PASS or WARN (advisory) | `reports/xdr_easm_posture_history.json` |
| 20 | `php artisan tenant:null-audit` | Tenant null record audit | Exit 0 (exit 1 = WARN, not FAIL) | console |
| 21 | `php artisan security:validate-secrets` | Secret strength validation | All secrets pass | console |
| 22 | `php artisan xdr:soak-analyze` | Analyze 6h soak report | Gate metrics within bounds | console |
| 23 | `python scripts/xdr_rule_registry_validate.py` | Rule registry integrity | `status=PASS rules=133 checks=21/21` | console |
| 24 | `python scripts/xdr_operator_readiness_check.py` | Runbook dependency check | `OVERALL=PASS` | `reports/xdr_operator_readiness.json` |

---

## Section 1 — Starting the Platform

### 1.1 Start datastores and observability

```bash
docker compose up -d
```

Verify all containers are healthy:

```bash
docker compose ps
```

Expected: all services show `healthy` or `running`. If any show `Exit` or `unhealthy`, check logs:

```bash
docker compose logs <service-name> --tail=50
```

### 1.2 Start pipeline services

```bash
docker compose --profile=strangler up -d
```

This starts: `ingestion-gateway`, `normalizer-worker`, `correlation-worker`, `alert-writer-service`, `incident-builder-service`.

Check pipeline health:

```bash
docker compose --profile=strangler ps
```

### 1.3 Start Laravel SOC application

```bash
docker compose --profile=app up -d
```

Or for production overlay:

```bash
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
    --profile=strangler --profile=app up -d
```

### 1.4 Bootstrap Redpanda topics

Run **before** the first event is sent. Safe to re-run; topic creation is idempotent.

```bash
python scripts/xdr_topic_bootstrap.py \
    --output reports/xdr_topic_bootstrap.json
```

Expected: `BOOTSTRAP_RESULT=PASS`

If topics already exist, the script reports them as already present and exits 0.

### 1.5 Run Laravel migrations

```bash
php artisan migrate --force
```

For a clean test environment:

```bash
php artisan migrate:fresh --force
```

---

## Section 2 — Stopping the Platform

### 2.1 Graceful stop (preserve data)

```bash
docker compose --profile=strangler --profile=app down
```

Data volumes are preserved. Re-starting with `up -d` resumes from last state.

### 2.2 Full teardown (data preserved in volumes)

```bash
docker compose down
```

### 2.3 Full teardown with volume wipe (destructive — demo reset only)

```bash
docker compose down -v
```

**WARNING:** This wipes all PostgreSQL, Redpanda, OpenSearch, ClickHouse, and Qdrant data. Use only for demo environment reset. Never run in a pilot deployment with real data.

After a volume wipe, run bootstrap and migrations again:

```bash
docker compose up -d
python scripts/xdr_topic_bootstrap.py
php artisan migrate --force
```

---

## Section 3 — Validating Topic / Bootstrap Readiness

```bash
python scripts/xdr_topic_bootstrap.py \
    --output reports/xdr_topic_bootstrap.json
```

Expected output:

```
BOOTSTRAP_RESULT=PASS
```

**If BOOTSTRAP_RESULT=FAIL:**  
Check Redpanda is running:

```bash
docker compose ps redpanda
docker compose logs redpanda --tail=50
```

Retry bootstrap once Redpanda is healthy.

**Topics required by the platform:**

| Topic | Consumer |
|---|---|
| `telemetry.raw` | normalizer-worker |
| `telemetry.normalized` | correlation-worker |
| `xdr.alerts` | alert-writer-service |
| `xdr.alerts.shadow.endpoint` | (advisory only — not consumed by alert-writer) |
| `alerts.created` | incident-builder-service |
| `telemetry.normalization_failed` | DLQ consumer (normalizer) |
| `xdr.correlation_failed` | DLQ consumer (correlation) |
| `xdr.alert_write_failed` | DLQ consumer (alert-writer) |

---

## Section 4 — Validating Posture (Local / Staging / Production)

### 4.1 Runtime posture check

```bash
python scripts/xdr_posture_check.py --profile=production \
    --output reports/xdr_posture_check.json
```

Expected: `OVERALL=PASS`

### 4.2 Production profile validation

```bash
python scripts/xdr_production_profile_validate.py --profile=production \
    --output reports/xdr_production_profile_validation.json
```

Expected: `OVERALL=PASS` (PDP-01 through PDP-14 all PASS)

### 4.3 Tenant isolation posture

```bash
python scripts/xdr_tenant_isolation_posture.py --profile=production \
    --output reports/xdr_tenant_isolation_posture.json
```

Expected: `OVERALL=PASS` (TIP-01 through TIP-14 all PASS, advisory INFO)

### 4.4 Rule registry integrity

```bash
python scripts/xdr_rule_registry_validate.py
```

Expected: `status=PASS  rules=133  checks=21/21`

### 4.5 Secret validation

```bash
php artisan security:validate-secrets
```

All secrets must pass. If any fail, rotate the affected tokens with:

```bash
openssl rand -hex 32
```

Update `.env`, restart affected services.

---

## Section 5 — Running Live Causal Proof

The causal proof validates that a real event flows from ingestion through normalization,
correlation, alert-write, and incident-build — producing a traceable lineage.

**Requires pipeline running (Section 1.2).**

```bash
python scripts/demo_causal_verify.py \
    --output reports/demo_causal_verify.json
```

Expected: `LIVE_CAUSAL_PROOF=PASS`

If `LIVE_CAUSAL_PROOF=FAIL`, check:
1. Ingestion gateway health: `curl http://localhost:8091/health`
2. Pipeline topic lag (Section 16.1)
3. Alert-writer logs: `docker compose logs alert-writer-service --tail=100`
4. Correlation-worker env: `XDR_CORRELATION_EVENT_LOOP_ENABLED=true`

---

## Section 6 — Running Pilot Live Evidence Validation

Validates the full evidence chain across 8 offline stages. Does not require Docker.

```bash
python scripts/xdr_pilot_live_validate.py \
    --output reports/xdr_pilot_live_validation.json
```

Expected: all 8 stages `PASS`.

If any stage fails, check the specific stage detail in the JSON report and address the
underlying issue (see troubleshooting in Section 16). Never force-pass a failing stage.

---

## Section 7 — Running Production Profile Validation

```bash
python scripts/xdr_production_profile_validate.py --profile=production \
    --output reports/xdr_production_profile_validation.json
```

Expected: `OVERALL=PASS`, no FAIL-level findings.

This checks:
- `docker-compose.prod.yml` present and parseable (PDP-01/02)
- Required env vars set (PDP-03 to PDP-08)
- Internal auth token posture (PDP-09 to PDP-12)
- Security headers configured (PDP-13/14)

---

## Section 8 — Running Recovery Validation

Static offline check — does not connect to the database.

```bash
python scripts/xdr_recovery_validate.py --profile=production \
    --output reports/xdr_recovery_validation.json
```

Expected: `OVERALL=PASS` (R-01 through R-08 all PASS).  
Advisory checks A-01 through A-04 may be WARN; they do not block PASS.

---

## Section 9 — Running Restore Drill

### 9.1 Dry-run (always safe — no DB changes)

```bash
python scripts/xdr_restore_drill.py \
    --output reports/restore_drill_dryrun.json
```

Expected: exit 0. Prints restore plan. No database is touched.

### 9.2 Execute mode (actual drill)

**Requires PostgreSQL client tools on PATH.**

```bash
python scripts/xdr_restore_drill.py --execute \
    --target-db xdr_drill_$(date +%Y%m%d) \
    --output reports/restore_drill_$(date +%Y%m%d_%H%M%S).json
```

Safety:
- The target database (`xdr_drill_*`) must differ from the source (`DB_DATABASE`) — enforced by PRE-06.
- The active database is never overwritten.
- The drill database is dropped automatically after post-restore checks.
- Dump file is retained at `reports/restore_drill/` — remove manually after confirming success.

Expected: exit 0, POST-01 and POST-02 PASS.

If POST-02 fails (spot-check tables missing), the backup is likely incomplete — investigate
`pg_dump` output and retry from a fresh backup.

---

## Section 10 — Running Live Soak Validation

### 10.1 Dry-run (default — no events sent)

```bash
python scripts/xdr_live_soak_validate.py
```

Expected: exit 0. Prints soak plan and pre-flight checks.

### 10.2 Execute mode

**Requires pipeline running (Section 1.2), with event loops enabled:**

```env
XDR_CORRELATION_EVENT_LOOP_ENABLED=true
XDR_EVENT_LOOP_ENABLED=true
XDR_SHADOW_CONSUMER_ENABLED=false
```

```bash
python scripts/xdr_live_soak_validate.py --execute \
    --duration-minutes 5 \
    --events-per-batch 10 \
    --batch-interval-ms 1000 \
    --output reports/live_soak_$(date +%Y%m%d_%H%M%S).json
```

Expected: `OVERALL=PASS`, all bounds B-01 through B-06 PASS.

Safety caps (enforced in script):
- Max events total: 1000
- Max duration: 60 minutes
- Max events per batch: 50
- Events use cloud/identity/SaaS domains only — no endpoint/DNS/firewall

---

## Section 11 — Running EASM Passive Scan

**Policy: passive only. No active probing. No exploit scanning.**

### 11.1 Via SOC CLI (Laravel)

First, ensure a `website_assets` row exists (add via SOC UI or artisan tinker):

```bash
php artisan easm:scan
```

### 11.2 Via Python script

```bash
python scripts/xdr_easm_passive_scan.py \
    --target https://example.com \
    --output reports/xdr_easm_passive_scan.json
```

Passive checks performed: DNS resolution, TLS certificate, HTTP headers, security headers, cookies, `robots.txt`, `sitemap.xml`.

Expected: `OVERALL=PASS` or `OVERALL=WARN` (advisory findings). `OVERALL=FAIL` indicates a scan failure, not a security issue.

All findings are advisory-only. No incidents are created. No detection rules are modified.

---

## Section 12 — Reviewing EASM Findings and History

### 12.1 Current findings

In the SOC UI: navigate to **EASM > Assets > [asset] > Findings**.

Findings are severity-tagged: `high`, `medium`, `low`, `info`.  
A `high` finding indicates a notable attack surface exposure (e.g., expired TLS, missing HSTS).  
Advisory only — no automatic remediation.

### 12.2 Posture history

```bash
python scripts/xdr_easm_posture_history.py \
    --output reports/xdr_easm_posture_history.json
```

Shows posture score trend, finding delta (new/resolved), and risk tier per asset.

### 12.3 High finding escalation

If a `high` severity finding appears on a production asset:
1. Record the finding in the pilot report.
2. Notify the asset owner.
3. Do NOT create a `security_incident` manually from an EASM finding.
4. Do NOT trigger endpoint containment as a response to an EASM finding.

See escalation matrix (Section 15) for the EASM high finding procedure.

---

## Section 13 — Reviewing Advisory Findings

Advisory findings are shadow-domain detections (endpoint, network, UEBA). They never create `security_incidents` and never trigger active response.

In the SOC UI: navigate to **Advisory > Findings**.

Required RBAC: `advisory.view`

### 13.1 Reviewing a finding

1. Open the finding detail page.
2. Read the behavioral evidence.
3. Choose: **Acknowledge** (low priority, noting context) or **Escalate to Investigation** (opens a threat hunt).
4. Note: acknowledging does not suppress future shadow detections.

### 13.2 Shadow consumer toggle

Advisory findings require `XDR_SHADOW_CONSUMER_ENABLED=true` in the alert-writer-service environment.  
**Default is `false`** — enable only when actively investigating shadow data.

---

## Section 14 — Reviewing DLQ Records

DLQ records are messages that failed normalization, correlation, or alert-write. They require analyst review before any replay.

In the SOC UI: navigate to **DLQ > Records**.

Required RBAC: `dlq.review`

### 14.1 Review workflow

1. Filter by `status = pending_review`.
2. Read `error_reason` and `raw_payload`.
3. Determine: is this a transient failure (replayable) or a corrupt/malicious payload (mark-reviewed-only)?
4. For transient failures: set status to `awaiting_replay`.
5. For corrupt/poison payloads: set status to `reviewed_no_replay`.

### 14.2 Replay

Replay is performed only via the Artisan command — never from HTTP handlers.

```bash
php artisan dlq:replay
```

Safe event types for replay: `cloud.*`, `identity.*`, `saas.*`.  
Pipeline failure types (correlation/alert-write failures) are filtered out automatically.

### 14.3 DLQ topic watermarks

Monitor DLQ topic watermarks for unexpected growth:

```bash
python scripts/xdr_live_soak_validate.py --quiet
```

Or check directly via Redpanda Admin UI (default: `http://localhost:8080`).

---

## Section 15 — Approving / Rejecting Response Workflows

Response workflows are analyst-initiated. The approver must be a different user from the recommender (self-approval is blocked).

In the SOC UI: navigate to **Responses**.

Required RBAC: `soc:workflow.execute` (recommend / decide)

### 15.1 Approving a response

1. Locate the pending response workflow (`status = pending_approval`).
2. Review the recommended action, target agent, and reason.
3. If the action is safe and proportionate: click **Approve**.
4. The system records your email as `approved_by`. A simulation is created (`approved_simulated`).
5. No actual agent command executes automatically — all Phase 1 actions are safe commands only (`collect_diagnostics`, `refresh_config`, `upload_health_snapshot`, `noop`).

### 15.2 Rejecting a response

1. Locate the pending response workflow.
2. Click **Reject** with a reason note.
3. Self-rejection (withdrawing your own recommendation) is permitted.

### 15.3 Endpoint response commands

Endpoint response commands (direct agent commands) require:
- `soc:response.approve` RBAC permission
- Approver ≠ creator (enforced in `EndpointResponseCommandService`)

Allowed command types (Phase 1): `noop`, `collect_diagnostics`, `refresh_config`, `upload_health_snapshot`.  
No execution-type commands (`execute_*`) are permitted.

---

## Section 16 — Troubleshooting Common Failures

### 16.1 Pipeline lag / events not flowing

**Symptoms:** Alerts not appearing in SOC UI after scenario runs.

Diagnosis:

```bash
# Check consumer lag on key topics
docker compose exec redpanda rpk topic consume telemetry.raw --num 1
docker compose exec redpanda rpk group list
```

Steps:
1. Verify pipeline services are running: `docker compose --profile=strangler ps`
2. Verify event loop is enabled: `XDR_CORRELATION_EVENT_LOOP_ENABLED=true`
3. Check alert-writer logs: `docker compose logs alert-writer-service --tail=100`
4. If consumer offset is out of range, the services will auto-recover (delete stale instance + recreate with ms-resolution group ID).
5. If DLQ topics show watermark growth, review DLQ records (Section 14).

### 16.2 Auth misconfiguration (403 responses on SOC routes)

**Symptoms:** SOC UI buttons invisible or return 403; tenant-scoped routes reject requests.

Steps:
1. Check `Gate::before()` registration: `AuthServiceProvider::boot()` must register `soc:<permission>` forwarding.
2. Verify user role in DB: `php artisan tinker` → `App\Models\User::find($id)->role`
3. Valid roles: `admin`, `analyst`, `detection_engineer`, `scenario_operator`, `viewer`
4. If `XDR_TENANT_STRICT_MODE=true`, all SOC routes require `X-Tenant-ID` header — verify front-end is sending it.
5. Check `user_tenant_memberships` for the user: membership must exist for the claimed tenant.

### 16.3 DLQ increasing

**Symptoms:** `dlq_records` count growing, DLQ topic watermarks rising.

Steps:
1. Review DLQ records: **SOC UI > DLQ > Records** or query `dlq_records WHERE status = 'pending_review'`.
2. Identify the error pattern: `error_reason` field.
3. For burst normalization failures: likely a malformed batch — isolate the `source_event_id`.
4. For correlation failures: check `xdr.correlation_failed` topic — Go service structured errors.
5. For alert-write failures: check `xdr.alert_write_failed` topic — PostgreSQL/OpenSearch errors.
6. Do NOT replay without review. Mark corrupt records as `reviewed_no_replay`.

### 16.4 Circuit breaker opens

**Symptoms:** Correlation falls back to legacy after 3 consecutive failures; `XDR_CORRELATION_FALLBACK_TO_LEGACY` triggers.

Steps:
1. Check correlation-worker logs: `docker compose logs correlation-worker --tail=100`
2. Verify Go service env: `XDR_CORRELATION_ENGINE=go`, `XDR_CORRELATION_SCOPE=identity-cloud`
3. Check for DB connection failures or Redpanda connectivity issues.
4. After root cause resolved: restart correlation-worker to reset the circuit breaker.
5. Fallback to legacy is safe — do not force promotion back to Go until the failure is understood.

### 16.5 Tenant isolation warning

**Symptoms:** `TenantBoundaryViolationException` in logs; 403 on SOC record routes.

Steps:
1. Check `tenant_id` on the record in question: `SELECT tenant_id FROM security_alerts WHERE id = ?`
2. Check user's tenant memberships: `SELECT * FROM user_tenant_memberships WHERE user_id = ?`
3. If `tenant_id` is null (legacy record), it should be accessible from all tenants — investigate `assertAccess()` logic.
4. Run tenant null audit: `php artisan tenant:null-audit`
5. Report to platform architect if legitimate violation is detected.

### 16.6 Restore drill failure

**Symptoms:** `xdr_restore_drill.py --execute` exits 1; POST checks fail.

Steps:
1. Check pre-flight output: missing `pg_dump`/`pg_restore` tools → install PostgreSQL client tools.
2. If PRE-06 fails: target DB = source DB — pass a unique `--target-db xdr_drill_YYYYMMDD`.
3. If POST-01 fails (migrations table missing): backup may be incomplete — take a fresh `pg_dump` and retry.
4. If POST-02 fails (spot-check tables missing): schema version mismatch — run `php artisan migrate` on the source before dumping.
5. Cleanup: if drill DB was left behind due to failure, manually `dropdb xdr_drill_*`.

### 16.7 Live soak failure (bounds exceeded)

**Symptoms:** `xdr_live_soak_validate.py --execute` exits 1; B-01 through B-06 fail.

| Bound failed | Likely cause | Action |
|---|---|---|
| B-01 accepted rate < 0.80 | Ingestion gateway rejecting events | Check auth (`HMAC_SECRET`), check rate limits |
| B-02 rate-limited > 0.10 | Too many events per second | Reduce `--events-per-batch` or increase `--batch-interval-ms` |
| B-03/B-04 latency high | Pipeline backpressure or slow DB | Check queue lag, OpenSearch health |
| B-05 publish failures > 0 | Network error to gateway | Verify gateway reachable; check firewall/proxy |
| B-06 circuit breaker opens | ≥3 consecutive 503s | See Section 16.4 circuit breaker recovery |

### 16.8 Topic bootstrap failure

**Symptoms:** `xdr_topic_bootstrap.py` exits with `BOOTSTRAP_RESULT=FAIL`.

Steps:
1. Verify Redpanda is running: `docker compose ps redpanda`
2. Check Pandaproxy is reachable: `curl http://localhost:8082/topics`
3. If Pandaproxy returns 503: Redpanda may be still starting — wait 30s and retry.
4. If specific topics fail: check `rpk topic list` for partial bootstrap — re-run the script.

---

## Section 17 — Collecting Evidence for Pilot Reports

At the end of a pilot session, collect evidence across all validators and archive the reports.

### 17.1 Run full evidence collection

```bash
# Posture checks (offline)
python scripts/xdr_posture_check.py --profile=production \
    --output reports/pilot/xdr_posture_check.json

python scripts/xdr_production_profile_validate.py --profile=production \
    --output reports/pilot/xdr_production_profile_validation.json

python scripts/xdr_tenant_isolation_posture.py --profile=production \
    --output reports/pilot/xdr_tenant_isolation_posture.json

python scripts/xdr_recovery_validate.py --profile=production \
    --output reports/pilot/xdr_recovery_validation.json

python scripts/xdr_rule_registry_validate.py

# Evidence chain (offline)
python scripts/xdr_pilot_live_validate.py \
    --output reports/pilot/xdr_pilot_live_validation.json

# Operator readiness (offline)
python scripts/xdr_operator_readiness_check.py \
    --output reports/pilot/xdr_operator_readiness.json
```

### 17.2 Evidence freeze command

```bash
php artisan pilot:evidence-freeze \
    --operator=<your-email> \
    --approved-by=<approver-email>
```

Operator and approver must be different (self-approval blocked).

### 17.3 Archive reports

```bash
mkdir -p reports/pilot/$(date +%Y%m%d)
cp reports/pilot/*.json reports/pilot/$(date +%Y%m%d)/
```

---

## Escalation Matrix

| Scenario | Indicator | Immediate action | Escalation path |
|---|---|---|---|
| Pipeline down | Events not flowing; DLQ topics not advancing; alerts absent > 5 min | Check pipeline service health (Section 16.1); restart failing service | Platform engineer; review correlation-worker and alert-writer logs |
| DLQ increasing | `dlq_records` count growing; DLQ topic watermarks advancing unexpectedly | Review DLQ records (Section 14); do NOT replay without analysis | Platform engineer; identify corrupt payload pattern |
| Auth misconfigured | 403 on SOC routes; `Gate::before()` not firing; role permissions wrong | Verify `AuthServiceProvider`, user roles, tenant memberships (Section 16.2) | Platform engineer; never bypass RBAC to resolve |
| Tenant isolation warning | `TenantBoundaryViolationException` in logs | Review isolation posture (Section 16.5); run `php artisan tenant:null-audit` | Platform architect; review `TenantBoundaryService` constants |
| EASM high finding | Passive scan returns severity=high on production asset | Record finding in pilot report; notify asset owner (Section 12.3) | Asset owner; platform architect if critical exposure |
| Restore drill failure | `xdr_restore_drill.py --execute` exits 1 | Follow Section 16.6; take fresh backup if POST checks fail | Platform engineer; do NOT declare pilot complete until drill passes |
| Live soak failure | `xdr_live_soak_validate.py --execute` exits 1; bounds exceeded | Identify failing bound; reduce load or fix gateway (Section 16.7) | Platform engineer; do NOT promote domains or claim stability until resolved |
| Circuit breaker triggered | Correlation fallback to legacy; 3 consecutive failures | Allow legacy to run; investigate correlation-worker (Section 16.4) | Platform engineer; restart correlation-worker only after root cause resolved |
| Rule registry integrity fail | `xdr_rule_registry_validate.py` exits 1 | Do NOT add to ACTIVE_ALLOWLIST; investigate registry file | Platform architect; git blame `docs/detection/rules/registry.v1.json` |

---

## Related Documents

| Document | Purpose |
|---|---|
| `docs/operations/OPERATIONAL_POSTURE.md` | Current domain status, correlation mode |
| `docs/operations/PRODUCTION_DEPLOYMENT_PROFILE.md` | Production deployment posture and env config |
| `docs/operations/BACKUP_RESTORE_RECOVERY.md` | Manual backup commands, RPO/RTO |
| `docs/operations/RESTORE_DRILL.md` | Restore drill workflow detail |
| `docs/operations/LIVE_SOAK_VALIDATION.md` | Live soak bounds, metrics, CLI reference |
| `docs/operations/EASM_PASSIVE_POSTURE_MONITORING.md` | EASM passive scan policy and checks |
| `docs/operations/EASM_POSTURE_HISTORY.md` | EASM risk scoring and trend |
| `docs/operations/RUNTIME_OBSERVABILITY_SLO.md` | SLO definitions and Grafana dashboards |
| `docs/security/TENANT_ISOLATION_POSTURE.md` | Tenant boundary architecture |
| `docs/security/RLS_DECISION_RECORD.md` | PostgreSQL RLS architecture decision |
| `docs/validation/VALIDATION_BASELINES.md` | Baseline pass criteria for all validators |
| `CLAUDE.md` | Operator safety rules, forbidden changes, feedback loop map |
