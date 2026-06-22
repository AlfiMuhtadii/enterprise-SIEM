# XDR Platform — Limitations and Corrected Claims

**Date:** 2026-06-22
**Status:** Authoritative. Read this before making any demo, thesis, or portfolio claim about the platform.

This document records the exact boundaries between what the platform implements, what it simulates, and what it does not do. It is the result of a read-only code audit conducted on 2026-06-22 and supersedes any looser language in earlier demo guides.

---

## 1. DemoScenarioSeeder — UI lifecycle data, not live detection proof

**What it does:**
`DemoScenarioSeeder::run()` calls `DemoPlatformPackagingService::launchDemoScenario()`, which:
- Reads a local JSON fixture from `fixtures/demo/scenarios/`
- Creates a `DemoScenarioRun` record in the `demo_scenario_runs` table
- Creates `DemoReadinessSnapshot` and `PlatformShowcaseExport` records

**What it does NOT do:**
- It does NOT POST events to the ingestion-gateway
- It does NOT produce messages on any Redpanda topic
- It does NOT trigger the Go correlation worker
- It does NOT create rows in `security_alerts` or `security_incidents`

**Correct claim:**
> "Running `php artisan db:seed --class=DemoScenarioSeeder` populates demo UI metadata for the Demo Platform module. It does not demonstrate live detection. The demo_scenario_runs table tracks scenario lifecycle; it is not an alert table."

**Evidence:** `database/seeders/DemoScenarioSeeder.php`, `app/Services/DemoPlatformPackagingService.php:23-56`

---

## 2. ingest_security_events.py — HTTP request log ingester, not XDR pipeline feeder

**What it does:**
`scripts/ingest_security_events.py` reads a JSONL file and writes rows to the `security_events` table:
```sql
INSERT INTO security_events (ts, event_type, event_id, ..., payload)
```

**What it does NOT do:**
- It does NOT post events to the ingestion-gateway Go service
- It does NOT produce messages on `telemetry.raw` or any Redpanda topic
- It does NOT trigger the normalizer-worker, correlation-worker, or alert-writer
- It does NOT create rows in `security_alerts`

**What `security_events` is:**
`security_events` is the HTTP request log table populated by `SecurityRequestLogger` middleware. It is the input table for the PHP ML detector (logistic regression on HTTP requests). It is a separate detection path from the Go XDR correlation engine.

**Correct claim:**
> "`ingest_security_events.py` feeds the HTTP-request-based ML classifier path only. To produce `security_alerts` rows via the XDR correlation engine, events must be POSTed to `ingestion-gateway POST /v1/ingest` with a valid HMAC-SHA256 signature and processed through the full Redpanda pipeline."

**Evidence:** `scripts/ingest_security_events.py:160`, `database/migrations/2026_03_04_000004_create_security_alerts_table.php`

---

## 3. demo_feed.py — Event tagger only; does not produce alerts by itself

**What it does:**
`scripts/demo_feed.py` reads a JSONL file, injects `demo_run_id` and sequential `trace_id` into each event, and writes:
- `storage/logs/<demo_run_id>_tagged.jsonl` — tagged events
- `storage/logs/<demo_run_id>-manifest.json` — time window for `--demo-run` filter

**What it does NOT do:**
- It does NOT send events anywhere
- It does NOT connect to the ingestion-gateway
- It does NOT produce Redpanda messages
- Calling it alone produces zero alerts

**The only path that produces `security_alerts` rows via the Go correlation engine:**
```
POST tagged events -> ingestion-gateway (POST /v1/ingest, HMAC-SHA256)
  -> telemetry.raw (Redpanda)
  -> normalizer-worker
  -> telemetry.normalized (Redpanda)
  -> correlation-worker (XDR_CORRELATION_SCOPE=identity-cloud, XDR_CORRELATION_EVENT_LOOP_ENABLED=true)
  -> xdr.alerts (Redpanda)
  -> alert-writer-service (XDR_EVENT_LOOP_ENABLED=true)
  -> security_alerts (PostgreSQL)
```
This requires `docker compose --profile strangler up` and both event loops enabled via env vars.

**Correct claim:**
> "`demo_feed.py` prepares events with a traceable `demo_run_id` for lineage purposes. It does not by itself prove end-to-end pipeline execution. Pipeline proof requires the tagged events to be sent through `ingestion-gateway/Redpanda` and processed by the Go correlation and alert-writer services."

**Evidence:** `scripts/demo_feed.py`, `services/correlation-worker/main.go:357-391`, `services/alert-writer-service/main.py:357-382`

---

## 4. demo_run_id filtering — time-window fallback only via current Go pipeline

**What works:**
`php artisan security:alerts-report --demo-run=<id>` reads the manifest file and filters `security_alerts` by `detected_at BETWEEN started_at AND ended_at+180s`.

**What does NOT work currently:**
The Go correlation worker's `makeAlert()` function does NOT copy `demo_run_id` from source events into the alert's `evidence` or `raw_event` fields. Therefore:
- `WHERE raw_event->>'demo_run_id' = ?` — always returns 0 rows from Go-produced alerts
- `WHERE evidence->>'demo_run_id' = ?` — always returns 0 rows from Go-produced alerts

The JSON filter clauses in `SecurityAlertsReportCommand` are present for future compatibility but are currently dead code for Go pipeline alerts.

**Correct claim:**
> "The `--demo-run` filter identifies alerts by time window (from the manifest). It does not prove field-level `demo_run_id` propagation through the Go correlation worker, because the Go worker does not currently pass this field into the published alert payload."

**Evidence:** `services/correlation-worker/main.go:694-742` (`makeAlert()` — evidence map does not include `demo_run_id`), `app/Console/Commands/SecurityAlertsReportCommand.php:31-35`

---

## 5. Shadow alert topics — producer-side only, no consumer

**What is implemented:**
The Go correlation worker publishes shadow alerts to:
- `xdr.alerts.shadow.endpoint` — endpoint behavioral detections (32 rules + behavioral analytics)
- `xdr.alerts.shadow.network` — DNS/proxy/firewall detections (9 rules)

**What is NOT implemented:**
No service in the codebase consumes `xdr.alerts.shadow.endpoint` or `xdr.alerts.shadow.network`. The alert-writer-service event loop subscribes only to `xdr.alerts` (the active topic). Shadow alerts are published and never read.

**Impact:** 121 shadow rules run correlation logic in the Go worker, but their output is unreachable from the SOC dashboard, the `security_alerts` table, the investigation workflow, and threat hunting. Shadow mode is architecturally complete from the producer side only.

**Correct claim:**
> "Shadow detection rules fire and produce alert payloads on shadow Kafka topics. These payloads are not currently persisted to any database, not visible in the SOC dashboard, and not available for investigation. They demonstrate rule logic but not end-to-end observability."

**Evidence:** `services/correlation-worker/main.go:241-267` (shadow publish), `services/alert-writer-service/main.py:358` (subscribes to `xdr.alerts` only)

---

## 6. ML classifier — HTTP request classifier on synthetic data, not XDR-wide model

**What it is:**
`scripts/train_ai_detector.py` implements a pure-Python multiclass logistic regression that classifies HTTP request events into: `normal`, `bruteforce`, `scan`, `injection`.

**Training data:**
`storage/app/security_dataset.csv` — synthetic data generated at project start (timestamps from 2026-03-04, IPs from RFC 5737 TEST-NET ranges, labels manually assigned). Not derived from real attack captures.

**Features:**
`path`, `status`, `latency_ms`, `has_sql_keywords`, `has_script_payload`, `method` — HTTP access log fields from the `security_events` table.

**What it is NOT:**
- It does NOT classify identity/cloud/SaaS telemetry
- It does NOT classify endpoint behavioral events
- It does NOT share features, labels, or a pipeline with the Go correlation engine
- It is NOT trained on real network traffic captures

**Relationship to XDR:**
The ML detector and the Go rule-based correlation engine are parallel, independent detection paths. The Go engine detects identity/cloud/SaaS threats via temporal correlation rules. The ML classifier detects HTTP-level attack patterns (brute-force, scanning, injection) against the Laravel application itself.

**Correct claim:**
> "The thesis title 'Multiclass Logistic Regression' refers to an HTTP request classifier applied to the Laravel application's own access logs. It is one detection component alongside the rule-based Go correlation engine. The two components operate on different data types, different tables, and through different pipelines. The ML model is trained on synthetic data labeled for academic demonstration purposes."

**Evidence:** `scripts/train_ai_detector.py:25-38`, `storage/app/security_dataset.csv:1-5`

---

## 7. SOAR — advisory/simulation/local workflow only

**What is implemented:**
- Playbook lifecycle (draft, active, deprecated) with dual-approval and simulation-first gates
- Blast-radius scoring per action type
- Append-only audit trail
- 10 allowed action types in Phase 1

**What the "execute" actions actually do:**
The 4 `simulate_*` actions (`simulate_endpoint_isolation`, `simulate_credential_revocation`, `simulate_firewall_block`, `simulate_token_revocation`) are `SIMULATION_ONLY_ACTIONS` — they produce simulation result records in the database. They do NOT call any external API, do NOT send commands to an endpoint agent, do NOT modify firewall rules.

The remaining 6 actions (`notify_analyst`, `create_incident`, `create_ticket`, `enrich_ioc`, `request_approval`, `create_watchlist_entry`) are local database operations. There are no outbound HTTP calls in `SoarOrchestrationService`. No Slack message is sent, no Jira ticket is created, no endpoint agent receives a command.

**Correct claim:**
> "SOAR orchestration implements the governance framework (approval gates, simulation-first, audit trail, blast-radius scoring) as a complete workflow engine. All actions are local advisory operations. No external systems are called. This is by design for the academic scope."

**Evidence:** `app/Models/SoarPlaybook.php:31-50`, `app/Services/SoarOrchestrationService.php:182-197`

---

## 8. Multi-tenancy — application-layer governance in a shared database

**What is implemented:**
`MultiTenantIsolationService` implements tenant isolation auditing, context propagation validation, namespace validation, and boundary violation reporting. These produce governance records in append-only tables (`tenant_isolation_audits`, `tenant_context_propagation_runs`, etc.) with `is_advisory=true`.

**What is NOT implemented:**
- No `ROW LEVEL SECURITY` in any PostgreSQL migration (confirmed by exhaustive grep)
- No `CREATE POLICY` in any migration
- No separate PostgreSQL schemas per tenant
- No `SET app.current_tenant` session variable enforcement
- All tables reside in the single `public` schema of a shared database

**Impact:** A query that omits a `WHERE tenant_id = ?` filter will return data across all tenants. There is no database-level enforcement preventing cross-tenant reads.

**Correct claim:**
> "Multi-tenancy is implemented as an application-layer governance and audit framework over a shared single-schema PostgreSQL database. It validates that tenant context is propagated correctly through the application but does not enforce isolation at the database level. This is appropriate for a single-organisation research platform."

**Evidence:** `app/Services/MultiTenantIsolationService.php:1-67`, database migrations (0 matches for `ROW LEVEL SECURITY`, `CREATE POLICY`, `schema_search_path`)

---

## Quick Reference — What Is Real vs. Simulated

| Component | Real / Implemented | Simulated / Advisory |
|---|---|---|
| Go correlation engine (identity/cloud/SaaS) | Real — 12 staged_active rules, 6h soak PASS | — |
| ingestion-gateway HMAC ingestion | Real — Go service, requires `--profile strangler` | — |
| normalizer-worker | Real — Go service, multi-format adapters | — |
| alert-writer Kafka consumer | Real — writes `security_alerts` when running | — |
| Redpanda event bus | Real — requires `docker compose up` | — |
| PHP ML classifier (HTTP brute/scan/injection) | Real — trains on CSV, classifies HTTP logs | Training data is synthetic |
| Endpoint agent (Python) | Real behavior collectors — posts to ingestion-gateway | Telemetry data in DB is seeded |
| Shadow endpoint/network correlation | Real rule logic in Go | No consumer; output unreachable |
| DemoScenarioSeeder | Creates demo UI records | Does NOT trigger detection pipeline |
| SOAR execution | Real governance workflow | No external API calls |
| Multi-tenant isolation | Real audit/governance records | No DB-level RLS enforcement |
| External integrations (Okta/Jira/Slack) | Real pipeline code | Simulated delivery by default |
| DNS/proxy/firewall analytics | Real analysis logic + shadow correlation | Network data is seeded/synthetic |

---

---

## 9. Live pipeline readiness validator

`scripts/validate_live_xdr_pipeline.py` is a read-only tool that confirms all pipeline services are running and correctly configured before a demo. It performs 9 checks:

1. ingestion-gateway `/health` reachable
2. normalizer-worker `/health` reachable
3. correlation-worker `/health` reachable
4. alert-writer-service `/health` reachable
5. incident-builder `/health` reachable
6. Redpanda REST API (`GET /topics`) reachable
7. Required Redpanda topics exist (`telemetry.raw`, `telemetry.normalized`, `xdr.alerts`)
8. `XDR_CORRELATION_EVENT_LOOP_ENABLED=true` — correlation-worker Redpanda consumer loop
9. `XDR_EVENT_LOOP_ENABLED=true` — alert-writer-service Redpanda consumer loop

**These are two independent flags for two separate services.** Both must be enabled for the full alert pipeline to function end-to-end.

| Flag | Service | What it controls |
|---|---|---|
| `XDR_CORRELATION_EVENT_LOOP_ENABLED` | correlation-worker (Go) | Consumes `telemetry.normalized`, produces `xdr.alerts` |
| `XDR_EVENT_LOOP_ENABLED` | alert-writer-service (Python) | Consumes `xdr.alerts`, writes `security_alerts` table |

The `docker-compose.yml` hardcodes `XDR_CORRELATION_EVENT_LOOP_ENABLED: "true"` for the containerised service (strangler profile). For local/out-of-Docker usage, both flags must be present in `.env`.

The script does NOT ingest data, publish to Redpanda, write to PostgreSQL, or start services.

```
python scripts/validate_live_xdr_pipeline.py
```

Exit codes: 0=all PASS, 1=any FAIL, 2=no FAIL but some UNKNOWN.

---

*This document is maintained alongside `docs/KNOWN_LIMITATIONS.md`. For academic scope boundaries see `docs/thesis/THESIS_POSITIONING.md`. For operational posture see `docs/operations/OPERATIONAL_POSTURE.md`.*
