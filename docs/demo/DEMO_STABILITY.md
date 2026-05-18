# Demo Stability Guide

Last updated: 2026-05-17

---

## Pre-Demo Validation Checklist

Run these in order before any demo or recording.

### Step 1 — Tests (2 minutes)
```powershell
php artisan test
```
**Expected:** `591 passed (2178 assertions)` — zero failures.
If any test fails: **STOP**. Do not demo until green.

### Step 2 — Registry (10 seconds)
```powershell
python scripts/xdr_rule_registry_validate.py
```
**Expected:** `status=PASS  rules=24  checks=21/21  failures=0`

### Step 3 — Resilience (15 seconds)
```powershell
python scripts/xdr_resilience_validate.py
```
**Expected:** `Resilience validation: 8/8 passed`

### Step 4 — Secrets (5 seconds)
```powershell
php artisan security:validate-secrets
```
**Expected:** Exit code 0. Review warnings — dev defaults are expected in demo environment. **Errors** = STOP.

### Step 5 — Migrations (5 seconds)
```powershell
php artisan migrate:status
```
**Expected:** All migrations showing `Ran` — no pending migrations.

### Step 6 — Demo data present (10 seconds)
```powershell
php artisan tinker --execute="echo App\Models\Investigation::count() . ' investigations, ' . App\Models\ResilienceRun::count() . ' resilience runs';"
```
**Expected:** Non-zero counts. If zero: run `php artisan db:seed --class=DemoSeeder`.

---

## Required Services Checklist

### Minimum Demo (Laravel-only — no pipeline)
- [x] PostgreSQL running
- [x] Laravel migrations applied
- [x] Demo data seeded
- [x] `php artisan serve` running on :8000
- [ ] Queue worker optional (needed only for Scenario Runner real mode)

### Full Stack Demo (with live pipeline)
- [x] All minimum requirements above
- [x] `docker compose up -d` (postgres, redpanda, clickhouse, opensearch, qdrant, grafana)
- [x] `docker compose --profile strangler up -d` (Go + Python services)
- [x] `php artisan queue:work --sleep=1 --tries=1` (background, for Scenario Runner)
- [x] Verify services: `curl http://localhost:8091/health` (ingestion-gateway)
- [x] Verify services: `curl http://localhost:8092/health` (normalizer-worker)

### Expected Healthy States
```json
ingestion-gateway:  {"status":"ok","service":"ingestion-gateway"}
normalizer-worker:  {"status":"ok","service":"telemetry-normalizer"}
alert-writer:       {"status":"ok","service":"alert-writer"}
incident-builder:   {"status":"ok","service":"incident-builder"}
```

---

## Fallback Demo Mode

If pipeline services are unavailable, the SOC control plane works fully standalone.

**What still works without pipeline:**
- All investigation workflow features (/investigations)
- Entity graph and risk scoring (/entity, /entity-risk)
- Response planning (/response-plans)
- Export center (/exports)
- Trace investigation (/traces)
- Detection rule governance (/detection)
- Security hardening dashboard (/security/hardening)
- Resilience validation dashboard (/resilience)
- Scenario Runner in STUB mode

**What requires pipeline:**
- Scenario Runner in REAL mode (requires ingestion-gateway + queue worker)
- Live alert ingestion from endpoint agent
- Live alert generation from telemetry

**Fallback script:**
```powershell
# Start minimum demo stack
php artisan migrate
php artisan db:seed --class=DemoSeeder
php artisan serve
# Navigate to http://localhost:8000
# Login with seeded admin credentials
```

---

## Deterministic Demo Scenarios

These scenarios produce consistent, reproducible outputs every time.

### Scenario A — Investigation Workflow (no pipeline needed)
1. Login as admin
2. `/investigations` — show queue
3. Create: Title="Demo Attack Investigation", entity_type=user, severity=high
4. Transition: new → triaged (set severity)
5. Assign: assign to analyst user
6. Add note: "Initial triage complete — escalating to investigation"
7. Transition: triaged → investigating
8. Show: audit trail in event log

**Expected:** Clean state machine transitions, all events in append-only log.

### Scenario B — Entity Risk (no pipeline needed)
1. `/entity-risk` — show risk dashboard
2. Select top-risk entity
3. `/entity-risk/{id}/breakdown` — show explainable factors
4. Navigate to entity page → timeline → graph

**Expected:** Deterministic score, explainable factor breakdown.

### Scenario C — Response Planning (no pipeline needed)
1. `/response-plans/recommendations` — trigger for a high-risk entity
2. Show generated recommendations: reset_password, revoke_session
3. Create plan
4. Submit for approval
5. Approve
6. Show advisory disclaimer on every page

**Expected:** ZERO system execution. completed_documented state = analyst documentation only.

### Scenario D — Resilience Validation (no pipeline needed)
1. `/resilience` — show 14 scenario grid
2. Run: `signature_verification_failure` (active scenario)
3. Show findings: non_destructive=pass, failure_logged=pass, trace_preserved=pass
4. Show metrics: failed_signature_count, non_destructive

**Expected:** PASS status, metrics recorded, report generated.

### Scenario E — Export (no pipeline needed)
1. `/exports` — Export Center
2. Select investigation from Scenario A
3. Export as JSON → show redacted output (no tokens/passwords)
4. Export as HTML → show self-contained file with disclaimer
5. `/exports/history` — show append-only audit log with EXP-YYYY-NNNNN IDs

**Expected:** TraceRedactor applied, disclaimer visible, audit log entry created.

### Scenario F — Scenario Runner Stub Mode (no pipeline needed)
1. `/scenario` — show scenario library
2. Click `failed_login_burst`
3. Run in STUB mode
4. Show scenario evidence (simulated stages)
5. Show detection result: passed/failed

**Expected:** Synthetic evidence created, no real alerts generated, deterministic result.

---

## Demo Login Credentials (from DemoSeeder)

Check seeder output for actual credentials. Typical demo accounts:

| Role | Email | Purpose |
|---|---|---|
| admin | admin@demo.local | Full access, hardening, resilience |
| analyst | analyst@demo.local | Investigation, response, export |
| viewer | viewer@demo.local | Read-only demonstration |

---

## Backup Assets

If live demo is not feasible (network issues, database issues):

**Screenshots to prepare in advance:**
- SOC dashboard (`/soc`)
- Investigation queue with at least 3 investigations
- Entity graph showing adjacency visualization
- Risk scoring breakdown for a high-risk entity (score ≥ 7.5)
- Response plan in `approved` state with advisory disclaimer visible
- Export history with 3+ EXP-YYYY-NNNNN entries
- Security hardening dashboard showing secret validation
- Resilience dashboard showing 14 scenarios with passed status

**Terminal output to prepare:**
- `php artisan test` output (591 passed)
- `python scripts/xdr_rule_registry_validate.py` output (PASS 21/21)
- `python scripts/xdr_resilience_validate.py` output (8/8 passed)

---

## Consistent Demo Talking Points

Use these exact phrases to maintain consistent academic framing:

| Context | Use | Avoid |
|---|---|---|
| Describing the platform | "hybrid detection platform" | "full XDR", "enterprise SOC" |
| Describing detection | "rule-based correlation" | "AI detection" |
| Describing endpoint | "shadow-only observation" | "endpoint protection" |
| Describing response | "advisory-only documentation" | "automated containment" |
| Describing ML component | "statistical baseline using logistic regression" | "AI-powered" |
| Describing scope | "research prototype" | "production-ready" |
| Describing soak results | "77,000 events/second at p95 < 81ms" | "real-time at enterprise scale" |

---

## Common Demo Failure Points and Recovery

| Failure | Cause | Recovery |
|---|---|---|
| 500 on /resilience | Migration not run | `php artisan migrate` |
| Blank entity graph | No entities projected | `php artisan tinker` → `EntityGraphService::projectFromAlerts()` |
| Scenario Runner fails | Queue worker not running | `php artisan queue:work --sleep=1 --tries=1` |
| Export download fails | storage/ not writable | `chmod -R 775 storage/` |
| Nav link 500 error | Route not found | Check `php artisan route:list` — `resilience.index` must exist |
| No investigation data | Seeder not run | `php artisan db:seed --class=DemoSeeder` |
