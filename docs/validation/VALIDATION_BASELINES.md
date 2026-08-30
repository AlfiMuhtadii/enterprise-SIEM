# Validation Baselines

Current pass criteria and expected outputs for all validation suites.
Last updated: 2026-06-27 (ENTERPRISE-063 + docker infra fixes)

This is the authoritative source for test counts and threshold values.
Update this file whenever a validation count changes (new tests, new rules).

---

## Primary Gate — Laravel Test Suite

```powershell
php artisan migrate:fresh --force && php artisan test
```

**Expected:** `4788 passed` — zero failures, zero skipped.
Last full verification: 4788 PHP (2026-07-07, after the alert/agent N+1 batches, TZ-AGENT-STALE fix, AI-context enrichment, and IOC/status refinements).
Python suites: 1669 total (endpoint_agent 191, alert_writer 49, incident_builder 36, ai_rag 1, scripts 5, demo_causal_verify 7, demo_feed 15, xdr_topic_bootstrap 1365).
Always prefix with `migrate:fresh --force` to avoid `QueryException` from stale schema state.

If any test fails: **STOP**. Do not commit, demo, or proceed.

Do NOT run parallel `php artisan test` processes against the same PostgreSQL test database.

---

## Endpoint Agent Python Tests

```powershell
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
```

**Expected:** `191 tests, 0 failures`

Coverage: heartbeat payload, process_start (/proc), network_connection (/proc/net/tcp), buffer retry/recovery, signed heartbeat, health state thresholds, quality metrics, behavioral snapshot, command execution (safe types only), threat hunting integration, host isolation simulation (config-gated, disabled by default), streaming engine (bounded queue, sequence tracking, local spool, rapid analytics), tamper visibility, spool stats, SOC heartbeat with spool_stats.

---

## Rule Registry Validator

```powershell
python scripts/xdr_rule_registry_validate.py
```

**Expected:** `status=PASS  rules=133  checks=21/21  failures=0`

Current registry breakdown:
- 12 `staged_active` — identity (6), cloud (5), SaaS (1)
- 32 `shadow` — endpoint behavioral (22 base + 5 cross-domain + 5 streaming)
- 8 `shadow` — low-level endpoint telemetry (LLTET Phase 1)
- 9 `shadow` — UEBA behavioral analytics
- 9 `shadow` — network (DNS/proxy/firewall)
- 3 `shadow` — threat-intel/IOC
- 20 `shadow` — advanced detection (cred/persist/evasion/lateral/container Phase 1)
- 40 `shadow` — detection depth expansion (cred/persist/evasion/lateral/cloud/container Phase 2)

Hard gate: endpoint + threat-intel + network rules are permanently blocked from `staged_active`. `ACTIVE_ALLOWLIST` is intentionally empty.

---

## Event Contract Validation

```powershell
python scripts/xdr_contract_validate.py --output reports/xdr_contract_validation.json
```

**Expected:** all contracts valid, exit 0

Contracts under `docs/contracts/`:
- `events/`: xdr.alerts, alerts.created, incidents.updated, ai.analysis.{requests,results,completed}
- `events/event-envelope.v1.schema.json` — base envelope (includes optional `event_signature`)
- `telemetry/endpoint/` — 8 event types + heartbeat
- `threat-intel/ioc.v1.schema.json`

---

## Event-Flow Resilience Validation

```powershell
python scripts/xdr_event_flow_resilience_validate.py --replays 3 --restart-services 0 --send-malformed 1 --output reports/xdr_event_flow_resilience_validation.json
```

For internal service mTLS, add HTTPS service URLs and client material:

```powershell
python scripts/xdr_event_flow_resilience_validate.py `
  --alert-writer-url https://alert-writer-service:8095 `
  --incident-builder-url https://incident-builder-service:8096 `
  --mtls-enabled `
  --mtls-ca certs/ca.crt `
  --mtls-client-cert certs/client.crt `
  --mtls-client-key certs/client.key
```

Invalid mTLS configuration exits `2` before runtime checks or report writes. The
client identity applies only to alert-writer and incident-builder requests; the
Redpanda REST producer remains a separately configured transport.

**Expected:** exit 0, all checks pass

---

## Resilience Scenario Validation

```powershell
python scripts/xdr_resilience_validate.py --output reports/resilience/resilience-validation-report.json
```

For an mTLS-enabled internal service plane, add `--mtls-enabled` with
`--mtls-ca`, `--mtls-client-cert`, and `--mtls-client-key`, and provide HTTPS
URLs for ingestion-gateway, normalizer, alert-writer, and incident-builder.
The internal client identity is not presented to `--laravel-url`.

**Expected:** `8/8 scenarios passed`

Scenarios: service health, consumer reconnect, backpressure, DLQ, signature failure, auth failure, endpoint shadow isolation, replay idempotency.

```powershell
php artisan resilience:validate
```

**Expected:** `14/14 scenarios passed` (9 simulation + 5 active)

Active scenario invariants:
- Invalid signature → never throws, never corrupts pipeline, logs hardening event
- Invalid auth token → 401, no write to `xdr_operational_events`
- Replay of same `event_id` → exactly 1 record (`insertOrIgnore` idempotency)
- Endpoint alert types → never appear in `security_alerts`

---

## Fleet Simulation Validation

```powershell
python scripts/xdr_fleet_simulation_validate.py
```

**Expected:** `8/8 passed` — all scenarios pass, exit code 0

Scenarios:
1. `healthy_fleet_baseline` — no indicators, no spool issues for healthy agent
2. `stale_agent_detection` — `heartbeat_gap` raised after 2-hour silence
3. `policy_drift_visibility` — `config_mismatch` raised for drifted config hash
4. `spool_capped_agent` — `spool_capped=True` at exactly 10 MiB (`STREAM_SPOOL_MAX_BYTES`)
5. `telemetry_lag_agent` — `queued_events`, `dropped_events`, `retry_count` propagated correctly
6. `tamper_advisory_only` — all 3 indicator types present, all `advisory=True`, `autonomous_action=False`
7. `mixed_degraded_fleet` — healthy + stale + drifted + capped correct across one run
8. `safety_invariants` — no forbidden enforcement APIs in agent module

Report: `reports/xdr_fleet_simulation_validation.json`

See: `docs/operations/ENDPOINT_FLEET_SIMULATION_GUIDE.md`, `docs/operations/ENDPOINT_FLEET_FAILURE_MATRIX.md`

---

## Fault Injection

```powershell
python scripts/xdr_fault_injection.py --output reports/resilience/fault-injection-report.json
```

For a production-like internal HTTPS deployment, scope the client identity to the
ingestion gateway, normalizer, and alert-writer service paths:

```powershell
python scripts/xdr_fault_injection.py `
  --ingest-url https://ingestion-gateway:8091 `
  --normalizer-url https://normalizer-worker:8092 `
  --alert-writer-url https://alert-writer-service:8095 `
  --mtls-enabled `
  --mtls-ca certs/ca.crt `
  --mtls-client-cert certs/client.crt `
  --mtls-client-key certs/client.key `
  --output reports/resilience/fault-injection-report.json
```

When mTLS is enabled, all three service URLs must use HTTPS and all certificate
paths are required. Configuration errors exit `2` before any injection or report
write. The Laravel invalid-token check intentionally does not receive this client
identity.

**Expected:** `5/5 injections passed` — all non-destructive, deterministic, local-only

---

## Secret Validation

```powershell
php artisan security:validate-secrets
php artisan security:validate-secrets --record
```

**Expected:** exit code 0. Warnings about dev defaults are expected in demo/dev. **Errors = STOP.**

Required secrets: `APP_KEY`, `XDR_INGEST_SECRET`, `XDR_INTERNAL_AUTH_SECRET`
Dev-default warnings: `SOC_WEBHOOK_SECRET`, `SOC_AGENT_ENROLLMENT_TOKEN`

---

## Docker Compose Validation

```powershell
docker compose config --quiet
```

**Expected:** exit code 0, no errors

---

## 6h Soak Validation (gate for permanent cutover)

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\run_xdr_correlation_soak_6h.ps1
```

Required gates (ALL must pass before permanent cutover):

| Gate | Required |
|---|---|
| fallback_count | = 0 |
| failure_count | = 0 |
| duplicate_rate | = 0 |
| goroutine_growth | = 0 |
| memory_usage | stable |
| p95_latency_ms | < 300 |
| latency_drift | none sustained |
| alert_type_match | >= 0.95 |
| evidence_match | >= 0.98 |
| alert_count_delta | <= 1–2% |

Last soak PASS: 2026-05-14
- p95 = 80.65 ms, 562M events processed, 77,981 eps, zero fallbacks/failures

See: `docs/validation/xdr_6h_soak_pass.md`

---

## Phase 1 Pre-Soak Gate Check

```powershell
php artisan soak:phase1-run --warm-up --duration=30
```

Pre-gates for staged-active empirical rules before executing the full 6h soak.
`--warm-up` seeds fixture confidence evidence via `rule:run-fixtures` + `rule:refresh-confidence`.

**Expected:** Decision: `PASS` — all 8 gates green.

| Gate | ID | Pass Condition |
|---|---|---|
| Staged-active rules count = 12 | P1G-01 | registry staged_active count ≥ 12 |
| Correlation engine is Go | P1G-02 | `XDR_CORRELATION_ENGINE=go` |
| Tier-1 fixture files on disk ≥ 12 | P1G-03 | `tests/fixtures/detection/tier1_batch1/*.json` count ≥ 12 |
| Empirical confidence evidence | P1G-04 | `rule_fixture_backlogs.confidence_source=empirical` count ≥ 1 |
| DLQ error count = 0 | P1G-05 | `dlq_records.status=error` count = 0 |
| Recent alerts > 0 | P1G-06 | `security_alerts` created in last 2h count > 0 |
| p95 latency < 300ms | P1G-07 | sourced from soak report |
| Fallback count = 0 | P1G-08 | sourced from soak report |

Last run result: Decision: **PASS** (2026-06-27, all 8 gates green).
NO_PROMOTION = true — this run does NOT authorize promotion. Only 6h soak PASS does.

See: `docs/validation/PHASE1_SOAK_EVIDENCE.md`
