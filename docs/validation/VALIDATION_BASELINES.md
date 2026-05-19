# Validation Baselines

Current pass criteria and expected outputs for all validation suites.
Last updated: 2026-05-20 (LLTET Phase 1)

This is the authoritative source for test counts and threshold values.
Update this file whenever a validation count changes (new tests, new rules).

---

## Primary Gate — Laravel Test Suite

```powershell
php artisan test
```

**Expected:** `1362 passed` — zero failures, zero skipped.
If any test fails: **STOP**. Do not commit, demo, or proceed.

Do NOT run parallel `php artisan test` processes against the same PostgreSQL test database.

---

## Endpoint Agent Python Tests

```powershell
python -m unittest discover -s tests/endpoint_agent -p "test_*.py" -v
```

**Expected:** `186 tests, 0 failures`

Coverage: heartbeat payload, process_start (/proc), network_connection (/proc/net/tcp), buffer retry/recovery, signed heartbeat, health state thresholds, quality metrics, behavioral snapshot, command execution (safe types only), threat hunting integration, host isolation simulation (config-gated, disabled by default), streaming engine (bounded queue, sequence tracking, local spool, rapid analytics), tamper visibility, spool stats, SOC heartbeat with spool_stats.

---

## Rule Registry Validator

```powershell
python scripts/xdr_rule_registry_validate.py
```

**Expected:** `status=PASS  rules=73  checks=21/21  failures=0`

Current registry breakdown:
- 12 `staged_active` — identity (6), cloud (5), SaaS (1)
- 32 `shadow` — endpoint behavioral (22 base + 5 cross-domain + 5 streaming)
- 8 `shadow` — low-level endpoint telemetry (LLTET Phase 1)
- 9 `shadow` — UEBA behavioral analytics
- 9 `shadow` — network (DNS/proxy/firewall)
- 3 `shadow` — threat-intel/IOC

Hard gate: endpoint + threat-intel rules are permanently blocked from `staged_active`. `ACTIVE_ALLOWLIST` is intentionally empty.

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

**Expected:** exit 0, all checks pass

---

## Resilience Scenario Validation

```powershell
python scripts/xdr_resilience_validate.py --output reports/resilience/resilience-validation-report.json
```

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
